# Guías: pautas y temas

Las guías de `/guias` son Markdown en `content/guias/{cl,mx,pe,ec}/{slug}.md`. Cada país tiene sus propios artículos, no traducciones. Una guía con `published_at` en el futuro queda oculta hasta ese día, así que se escriben por adelantado y salen solas.

Este archivo no se publica (solo se leen los `.md` de las carpetas de país).

## Calendario

- Una guía por semana y país, los miércoles.
- Mantener al menos **4 semanas en cola** por país. Cuando quedan menos de 3, toca escribir las siguientes.
- Cada lote rota los tipos: trámites, precios, guía de compra, industria.

## Formato

```
---
title: "Título en lenguaje natural"
meta_title: "Título corto | Roodos"
meta_description: "Una o dos frases, menos de 160 caracteres."
slug: igual-al-nombre-del-archivo
published_at: AAAA-MM-DD
updated_at: AAAA-MM-DD
---
```

Entre 800 y 1.200 palabras. Intro corta, secciones con `##`, listas cuando ayudan, un `## En resumen` y un cierre con link a `/search` o a una página de modelo.

## Reglas

- **Vocabulario del país.** Chile: auto, automotora, patente. México: auto, seminuevo, agencia, placas. Perú: auto, concesionario, placa, papeletas. Ecuador: carro, patio o concesionaria, matrícula.
- **Sin montos ni plazos que cambian cada año** (tasas, aranceles, multas). Explicar el criterio (por ejemplo, "1,5% sobre el mayor valor entre precio y tasación") y enlazar la ficha oficial. Porcentajes fijos por ley sí.
- **Verificar los trámites** en fuentes oficiales (ChileAtiende, SII, Registro Civil, SUNARP, SAT, SRI, ANT, REPUVE, gob.mx) antes de escribir. Si las fuentes no coinciden, no dar el dato.
- **Sin links a Autoraptor** ni a empresas privadas (salvo que se pida expresamente). Links externos solo a sitios oficiales.
- **Links internos:** `/search`, `/modelo/{slug}`, `/marca/{slug}` y `/guias/{slug}` del mismo país, solo a guías con `published_at` igual o anterior (hay un test que lo revisa).
- **Precios:** nunca escribir cifras de precios a mano. Usar `[[precios:{slug-del-modelo}]]` en un párrafo propio; se reemplaza por una tabla con datos del índice. El slug tiene que existir en `car_model`; los de la lista de temas pendientes están verificados. Un modelo nuevo solo se agrega si alguien confirma su slug en Manticore. **Perú no tiene tablas** (mezcla soles y dólares y el índice no guarda la moneda): ahí no se escriben artículos de precios.
- **No inventar datos de modelos** (motores, versiones, años de generación) si no están verificados. Mejor hablar de versión, transmisión, kilometraje y uso en general.

## Temas publicados o en cola

| País | Temas |
|---|---|
| cl | pie y cuotas · transferir un auto · precio Suzuki Swift · qué revisar antes de comprar · tasación en parte de pago |
| mx | tecnología de las agencias · documentos para comprar un seminuevo · precio Nissan Versa · evitar fraudes · cómo fija una agencia el precio |
| pe | stock de concesionarios · transferencia vehicular · auto convertido a GNV/GLP · si fue taxi · seminuevo certificado |
| ec | qué pasa cuando le escribes a una concesionaria · traspaso · precio Chevrolet Aveo · qué revisar antes de comprar · consignación |

Al agregar una guía, sumarla a esta tabla y tacharla de la lista de abajo.

## Temas pendientes

### Chile
- Precios: `chevrolet-sail`, `toyota-yaris`, `kia-morning`, `hyundai-accent`, `nissan-versa`, `toyota-hilux`.
- Trámites: permiso de circulación y SOAP al comprar un usado; revisión técnica (plazos según año); qué hacer si el auto tiene una prenda.
- Compra: comprar un auto usado a un particular vs. a una automotora; cómo detectar un kilometraje adulterado; auto usado para un primer conductor.
- Industria: qué es una automotora "multimarca"; cómo funciona la garantía de un usado; cuándo se venden más baratos los autos (temporadas).

### México
- Precios: `chevrolet-aveo`, `nissan-march`, `volkswagen-vento`, `kia-rio`, `toyota-corolla`, `nissan-np300`.
- Trámites: baja de placas y emplacamiento en otro estado; verificación vehicular al comprar un seminuevo; refacturación.
- Compra: comprar seminuevo a un particular vs. a una agencia; qué revisar en una pickup usada; crédito para seminuevos.
- Industria: cómo funcionan los programas de seminuevos certificados de las marcas; cómo se calcula el enganche.

### Perú
- (Sin artículos de precios hasta que el índice guarde la moneda.)
- Trámites: SOAT al comprar un usado; revisión técnica (cuándo corresponde); papeletas de un auto que compraste; impuesto vehicular.
- Compra: auto usado de remate o subasta; autos de importación usados; cómo negociar el precio de un usado; dólares o soles al comprar un auto.
- Industria: cómo funciona el crédito vehicular en un concesionario; parte de pago en Perú.

### Ecuador
- Precios: `kia-rio`, `chevrolet-sail`, `kia-picanto`, `toyota-hilux`, `hyundai-accent`.
- Trámites: matriculación anual al comprar un usado; revisión técnica vehicular; levantamiento de reserva de dominio; impuesto a la propiedad de vehículos.
- Compra: carro usado de la Costa vs. de la Sierra (óxido, altura); comprar una camioneta usada; cómo pagar de forma segura.
- Industria: cómo te tasa un patio tu carro; crédito directo de patios y casas comerciales.
