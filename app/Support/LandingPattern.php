<?php

namespace App\Support;

/**
 * The shapes the original landing generator built titles from ("<tipo> <marca> <ano>", "venta de
 * <tipo> en <ciudad>"...). Another system later crossed ad words into titles with no meaning
 * ("Marca 185000 Recorrido Renault"); a title that cannot be read as one of these shapes, with
 * the country's makes, models and places, is one of those.
 */
class LandingPattern
{
    /** The original generator's patterns. */
    const PATTERNS = [
        '<pais> <tipo>', 'venta de <tipo>', '<marca> <pais>', 'venta de <tipo> <condicion>', '<marca>',
        '<marca> <modelo>', '<tipo> <ciudad>', '<tipo>', '<tipo> <condicion> <pais>', 'compra y venta de <tipo>',
        '<tipo> <marca>', '<modelo>', 'remates de <tipo>', '<marca> <modelo> <ano>', '<modelo> <pais>',
        'venta de <tipo> en <ciudad>', '<tipo> <condicion> <ciudad>', '<tipo> <condicion>', '<marca> <tipo>',
        'venta de <tipo> <condicion> en <ciudad>', '<pais> <tipo> <ciudad>', '<pais> <tipo> <ciudad> <condicion>',
        'venta <tipo> <condicion> <marca>', 'venta <tipo> <condicion> <marca> <modelo>',
        'venta <tipo> <condicion> <marca> <modelo> <ciudad>', '<tipo> <marca> <ano>', '<tipo> <marca> <condicion>',
        '<tipo> <marca> <modelo> <condicion>', '<tipo> <modelo> <condicion> <ciudad>', '<tipo> <pais> <ano>',
        '<marca> <modelo> <color>', '<marca> <modelo> <color> <ano>',
    ];

    /** Shapes of an older generator (mostly Chile, in lower case) that also read as real searches. */
    const VARIANTS = [
        '<marca> <modelo> <ano> <color>', '<marca> <modelo> <ano> <ciudad>', '<marca> <modelo> <ciudad>',
        '<marca> <modelo> <color> <ciudad>', '<marca> <modelo> <pais>', '<modelo> <marca> <ano>',
        '<marca> <ano>', '<marca> <ciudad>', '<tipo> <marca> <modelo> <condicion> <ciudad>',
        '<tipo> <marca> <condicion> <ciudad>', '<tipo> <ano> <condicion> <ciudad>', '<tipo> <marca> <modelo> <ano>',
        'venta <tipo> <condicion> <marca> <ano> <ciudad>', 'venta <tipo> <condicion> <ciudad>',
    ];

    const TYPES = [
        'autos', 'auto', 'carros', 'carro', 'vehiculos', 'vehiculo', 'camionetas', 'camioneta', 'motos', 'moto',
        'motocicletas', '4x4', 'jeeps', 'camiones', 'camion', 'buses', 'bus', 'mini buses', 'minibuses',
        'clasicos de coleccion', 'clasicos', 'furgonetas', 'furgones', 'remolques', 'maquinaria', 'maquinarias',
        'cuatrimotos',
    ];

    const COLORS = [
        'blanco', 'blanca', 'negro', 'negra', 'gris', 'gris oscuro', 'gris plata', 'plata', 'plateado', 'plateada',
        'rojo', 'roja', 'azul', 'azul marino', 'verde', 'amarillo', 'naranjo', 'naranja', 'cafe', 'beige', 'dorado',
        'burdeo', 'vino', 'morado', 'celeste', 'marron', 'grafito', 'rosado', 'turquesa', 'bronce', 'champagne',
    ];

    const COUNTRY_WORDS = ['cl' => 'chile', 'mx' => 'mexico', 'pe' => 'peru', 'ec' => 'ecuador', 'lab' => 'laboratorio'];

    const LITERALS = ['venta', 'de', 'en', 'compra', 'y', 'remates'];

    /**
     * Words of ad labels and sale talk ("Marca", "Recorrido", "Condición del ítem", "vendo o
     * permuto", "chocado"): never part of a model's version. Any other word after a model is
     * ("Luv 4x2 Cabina Doble", "Grand Cherokee Limited", "Accent RB GL 1.6").
     */
    const NOT_VERSION = [
        'marca', 'modelo', 'modelos', 'version', 'ano', 'tipo', 'motor', 'kilometros', 'kilometraje', 'km', 'kms',
        'recorrido', 'puertas', 'condicion', 'del', 'item', 'combustible', 'transmision', 'color', 'precio', 'vendo',
        'vende', 'permuto', 'cambio', 'oferta', 'negociable', 'negociables', 'unico', 'dueno', 'desarme', 'chocado',
        'chocada', 'repuestos', 'otros', 'otras', 'otro', 'otra', 'sistema', 'carroceria', 'cilindrada', 'traccion',
        'si', 'no', 'estado', 'excelente', 'urge', 'financiamiento', 'credito', 'ocasion', 'fotos', 'valor', 'cel',
        'llamar', 'whatsapp', 'con', 'sin', 'para', 'por', 'o', 'la', 'el', 'los', 'las', 'muy', 'buen', 'buena',
    ];

    /** Words a model's version can have at most. */
    private const MAX_VERSION_WORDS = 5;

    /** Longest name, in words, tried for each kind of piece. */
    private const MAX_WORDS = 6;

    /** @var array<string, true> every allowed shape and every start of one, to prune the search */
    private array $prefixes = [];

    /** @var array<string, true> */
    private array $shapes = [];

    private array $types;

    private array $colors;

    private array $conditions;

    private array $notVersion;

    public function __construct(private LandingVocabulary $vocabulary, private string $country)
    {
        foreach (array_merge(self::PATTERNS, self::VARIANTS) as $shape) {
            $this->shapes[$shape] = true;
            $parts = explode(' ', $shape);
            for ($i = 1; $i <= count($parts); $i++) {
                $this->prefixes[implode(' ', array_slice($parts, 0, $i))] = true;
            }
        }
        $this->types = array_fill_keys(self::TYPES, true);
        $this->colors = array_fill_keys(self::COLORS, true);
        $this->conditions = array_fill_keys(array_keys(LandingClassifier::CONDITION_WORDS), true);
        $this->notVersion = array_fill_keys(array_merge(self::NOT_VERSION, self::LITERALS), true);
    }

    /**
     * The shape a title reads as, or null when it reads as none.
     */
    public function shape(string $title): ?string
    {
        $words = LandingClassifier::words($title);

        return $words === [] ? null : $this->walk($words, 0, '', null, null, 0);
    }

    /**
     * Depth-first over the ways to read $words[$i..] as pieces, only along allowed shapes.
     *
     * @param ?string[] $makes makes the title names so far (a model must belong to one of them)
     * @param ?string $last the last piece read
     * @param int $versionWords version words read since the last model
     */
    private function walk(array $words, int $i, string $shape, ?array $makes, ?string $last, int $versionWords): ?string
    {
        if ($i === count($words)) {
            return isset($this->shapes[$shape]) ? $shape : null;
        }

        foreach ($this->pieces($words, $i) as [$piece, $end, $pieceMakes]) {
            $nextMakes = $makes;
            if ($pieceMakes !== null) {
                if ($makes !== null) {
                    $common = array_values(array_intersect($makes, $pieceMakes));
                    if ($common === []) {
                        continue; // A model of another make ("Peugeot Bravo").
                    }
                    $nextMakes = $common;
                } else {
                    $nextMakes = $pieceMakes;
                }
            }

            // The make said twice ("Mazda Mazda 3"), a city followed by its state ("Xochimilco Distrito
            // Federal"), two colors ("gris grafito") or the version after the model ("Serie 3",
            // "Grand Cherokee Limited") add nothing to the shape.
            $isVersion = $piece === '<version>';
            if ($isVersion && ($last !== '<modelo>' || $versionWords >= self::MAX_VERSION_WORDS)) {
                continue;
            }
            $repeated = $isVersion
                || ($piece === '<marca>' && $last === '<marca>' && $makes !== null && $nextMakes !== [])
                || (($piece === '<ciudad>' || $piece === '<color>') && $last === $piece);

            $next = $repeated ? $shape : ltrim($shape . ' ' . $piece);
            if (!isset($this->prefixes[$next])) {
                continue;
            }
            $found = $this->walk($words, $end, $next, $nextMakes, $repeated ? $last : $piece, $isVersion ? $versionWords + 1 : 0);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Every piece that can start at $words[$i]: [piece, end position, makes it implies or null].
     */
    private function pieces(array $words, int $i): \Generator
    {
        $word = $words[$i];
        if (in_array($word, self::LITERALS, true)) {
            yield [$word, $i + 1, null];
        }
        if (preg_match('/^(19[5-9]\d|20[0-3]\d)$/', $word)) {
            yield ['<ano>', $i + 1, null];
        }
        if ($word === (self::COUNTRY_WORDS[$this->country] ?? null)) {
            yield ['<pais>', $i + 1, null];
        }
        if (isset($this->conditions[$word])) {
            yield ['<condicion>', $i + 1, null];
        }

        for ($end = min(count($words), $i + self::MAX_WORDS); $end > $i; $end--) {
            $phrase = implode(' ', array_slice($words, $i, $end - $i));
            if (isset($this->types[$phrase])) {
                yield ['<tipo>', $end, null];
            }
            if (isset($this->colors[$phrase])) {
                yield ['<color>', $end, null];
            }
            if (($makes = $this->vocabulary->makesNamed($phrase)) !== []) {
                yield ['<marca>', $end, $makes];
            }
            if (($makes = $this->vocabulary->modelMakes($phrase)) !== []) {
                yield ['<modelo>', $end, $makes];
            }
            if ($this->vocabulary->isPlace($phrase)) {
                yield ['<ciudad>', $end, null];
            }
        }

        // Version after a model: any word but ad labels, and not a year, which is its own piece.
        if (!isset($this->notVersion[$word]) && !preg_match('/^(19|20)\d{2}$/', $word)) {
            yield ['<version>', $i + 1, null];
        }
    }
}
