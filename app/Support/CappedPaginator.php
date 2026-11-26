<?php

namespace App\Support;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Paginador de los listados: muestra el total real pero no enlaza más allá de MAX_PAGE.
 * Las páginas profundas obligan a Manticore a ordenar y descartar todo lo anterior
 * (la 800 son ~19.000 resultados por petición) y solo las visitan bots; los avisos
 * ya están en el sitemap.
 */
class CappedPaginator extends LengthAwarePaginator
{
    public const MAX_PAGE = 50;

    public function __construct($items, $total, $perPage, $currentPage = null, array $options = [])
    {
        parent::__construct($items, $total, $perPage, $currentPage, $options);
        $this->lastPage = min($this->lastPage, self::MAX_PAGE);
    }

    public static function beyondCap($page): bool
    {
        return (int) $page > self::MAX_PAGE;
    }
}
