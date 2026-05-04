# Changelog

Todos los cambios notables del plugin se documentan en este archivo.

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/).
Versionado [SemVer](https://semver.org/lang/es/).

## [1.28.0] - 2026-05-02

Hardening operativo de la cola de envío: cuatro fixes que eliminan modos
de falla silenciosa donde una orden podía quemar consecutivos extra,
correr concurrente con el cron, o quedarse en `failed` sin que nadie se
enterara. Consolida planes #1, #2, #3 y #5 (#4 — recovery automático de
polls de acuse — queda diferido).

### Added
- **Comando WP-CLI `wp fe-woo unblock_failed`** — reactiva en lote items
  varados en `status='failed'` (status → `retry`, attempts → `0`,
  error_message → `NULL`). Acepta `--since`, `--limit`, `--max-attempts=N`
  para override per-item, y por defecto excluye rechazos terminales de
  Hacienda. Pensado para reanimar transitorios de red / outages de
  Hacienda sin tocar SQL.
- **Método `FE_Woo_Factura_Generator::rebuild_xml_for_clave()`** —
  reconstruye el XML para una clave existente sin consumir nuevo
  consecutivo. Usado por el retry del queue cuando la clave previa
  quedó "pending" tras un POST fallido.
- **Meta `_fe_woo_factura_clave_pending`** — guarda la clave generada
  ANTES del sign+POST a Hacienda. Sirve como ack temprano para que el
  retry del próximo tick pueda recuperar sin regenerar consecutivo.

### Fixed
- **Reuso de clave en retry single-factura** (Plan #2): cuando un POST a
  Hacienda fallaba después de `generate_clave` pero antes de persistir
  el meta confirmado, el siguiente tick **regeneraba clave nueva y
  consumía OTRO consecutivo**. Ahora el retry consulta primero
  `/recepcion/{clave}` con la clave pending:
  - Hacienda la tiene (cualquier estado) → skip POST, recovery via acuse.
  - Hacienda 404 → rebuild XML con misma clave + sign + re-POST.
  - Hacienda error/timeout → throw, retry próximo tick (clave
    preservada). Cero consecutivos perdidos por outages de red.
- **Per-order lock en cron worker** (Plan #3): `process_item()` ahora
  adquiere `FE_Woo_Order_Lock` antes de `mark_processing` y lo libera
  en `finally`. Cierra la ventana de race contra rutas manuales
  (Reintentar, Ejecutar, `reexecute_invoice`) que ya tomaban el mismo
  lock. Sin esto un operador clickeando "Ejecutar" mientras el cron
  estaba mid-POST sobre el mismo order_id producía dos consecutivos
  consumidos y dos POST con misma intención fiscal.
  - Comportamiento ante contención: log + skip (no incrementa attempts);
    próximo tick lo intenta cuando el lock libere o expire (TTL 300s).
- **Threshold del `health_check`** (Plan #5): hardcoded a 5 mientras el
  schema de la cola usa `max_attempts=3` por defecto. El email diario
  de "cola requiere atención" nunca se disparaba porque ningún item
  realmente fallido (3 intentos) llegaba al threshold (5+). Bajado a 3
  para alinear con schema y con `unblock_failed`. Body del email ahora
  menciona `wp fe-woo unblock_failed` como herramienta canónica.

### Changed
- **`clear_invoice_data()`** ahora también borra
  `_fe_woo_factura_clave_pending`. Si el operador hace force-rerun, la
  pending se limpia para no disparar la rama de recovery sobre datos
  obsoletos.
- **`query_invoice_status()`** del API client devuelve
  `not_found = true` cuando Hacienda responde HTTP 404 (clave no
  encontrada). Permite distinguir "Hacienda nunca recibió" de "Hacienda
  está temporalmente caída" en el path de recovery.

### Notes
- **Multi-factura no se modifica** en este release: el flujo
  `generate_and_send_multi_facturas` mantiene su retry parcial actual
  (resume desde la primera factura con `status='sent'`). El caso
  análogo de pending-clave-en-multi queda para un release posterior si
  hace falta.
- **Schema sin cambios**: `max_attempts` sigue en 3 por default. Plan #1
  permite override per-item via `--max-attempts=N`.
- **Plan #4 diferido**: recovery automático de polls de acuse perdidos
  (sweep de órdenes en `procesando` sin evento cron pendiente). El JS
  recheck del admin + `wp fe-woo find_orphans` cubren el caso por ahora.

## [1.27.0] - 2026-05-02

### Changed
- **`OtrasSenas` del Receptor ahora es opcional** en checkout y admin de orden.
  Al construir el XML del Receptor se concatena siempre el sufijo
  `| otras senas especificadas por el emisor` al texto del cliente, con
  truncado a 250 chars si el resultado lo excede. Garantiza el `minLength=5`
  del XSD v4.4 sin bloquear órdenes con campo vacío.
- UI de checkout y admin: removida la marca de campo requerido (`*`) y el
  hint "Mínimo 5 caracteres, máximo 250.". Reemplazado por "Opcional. Si lo
  dejas vacío, completaremos genéricamente para Hacienda. Máximo 250 caracteres."

### Removed
- Validación pre-flight de longitud de `OtrasSenas` del Receptor en
  `validate_receptor_fields()` (la regla ahora es estructural en
  `build_receptor`).
- Validación de campo requerido y longitud (5–250) en
  `validate_factura_electronica_fields()` durante checkout.
- Check `>= 5` chars en la condición de emisión del bloque `<Ubicacion>` del
  Receptor en `build_receptor`. La nueva condición solo requiere
  `provincia && canton && distrito` válidos contra el catálogo CR.

### Added
- Constante `FE_Woo_Factura_Generator::RECEPTOR_OTRAS_SENAS_SUFFIX`.

### Notes
- **Emisor sin cambios**: sigue requerido y validado (5–250) en settings y
  pre-flight. Esto es config one-time del admin del sitio; un emisor mal
  configurado debe arreglarse, no enmascararse.
- Órdenes POS sin ubicación capturada continúan emitiendo XML del Receptor
  sin bloque `<Ubicacion>` (sin regresión).

## [1.26.1] - 2026-05-02

### Fixed
- **Empaquetado del autoload de Composer**: el `vendor/composer/autoload_*.php`
  commiteado en v1.26.0 se generó con dev deps activas, así que registraba
  paquetes (`myclabs/deep-copy`, `phpunit`, `sebastian/*`, `doctrine/instantiator`,
  `nikic/*`, `phar-io/*`, `theseer/*`) que el `.gitignore` del propio plugin
  excluye del commit. Resultado: en cualquier instalación vía Composer
  (zip dist) WordPress moría con
  `Failed opening required '.../myclabs/deep-copy/src/DeepCopy/deep_copy.php'`
  durante el bootstrap del plugin.
  - `vendor/` regenerado con `composer install --no-dev --optimize-autoloader`.
  - Añadido script `composer release-vendor` para automatizar el comando
    correcto antes de cada tag.
  - Documentado el flujo de release en `README.md`.

## [1.26.0] - 2026-05-01

### Added
- Pre-flight de longitud de `OtrasSenas` (5–250 caracteres) en emisor y
  receptor antes de firmar — evita rechazos `OtrasSenas length` de Hacienda
  y que se queme un consecutivo en validaciones que solo el XSD detectaría.
- Defense-in-depth en `build_emisor` / `build_receptor`: trim, truncado a
  250 chars y omisión del bloque `Ubicacion` del receptor cuando
  `OtrasSenas` < 5 chars.
- Tests `tests/DocumentStorageTest.php` (11 casos) cubriendo round-trip
  save/get, fallback al layout legacy, idempotencia de delete y cache
  del `FE_Woo_Document_Storage` introducido en 1.25.0.

### Changed
- Notices del admin (`order-admin.js`): soporte de tipo `info`, render
  multi-línea (`\n` → `<br>`) para pre-flights con varias viñetas, y
  errores persistentes hasta que el admin los descarte.

## [1.25.0] - 2026-05-01

### Changed
- **Document storage layout fechado**: los archivos pasan de
  `factura-electronica/order-{id}/` a `factura-electronica/Y/m/d/order-{id}/`,
  derivando `Y/m/d` de la fecha de creación de la orden. Reduce la cantidad
  de entradas por directorio en sitios con miles de órdenes.
- `delete_order_documents()` limpia tanto el directorio fechado como el
  legacy plano.

### Added
- Cache per-order de la fecha resuelta en `FE_Woo_Document_Storage` para
  evitar llamadas repetidas a `wc_get_order()` durante una request.

### Compatibility
- Sin migración requerida: los getters de lectura (`get_xml_path`,
  `get_acuse_path`, `get_acuse_xml_path`, `get_pdf_path`) caen al layout
  legacy plano cuando el archivo todavía vive ahí.

## [1.24.0] - 2026-05-01

### Fixed
- `find_orphans` ahora soporta múltiples emisores (antes asumía emisor
  único y falsamente marcaba como huérfanas órdenes de otros emisores).
- Pre-flight de receptor: validación de campos requeridos antes del envío.

## [1.23.3] - 2026-05-01

### Changed
- Texto de ayuda fijo para el campo "código de actividad económica" en el
  checkout (el dinámico se desincronizaba según el cache de WC).

## [1.23.2] - 2026-05-01

### Fixed
- HPOS stale-instance bug: cuando dos paths cargaban `$order` y uno borraba
  meta, el otro hacía `UPDATE WHERE id=X` que afectaba 0 filas. La meta de
  clave/factura no persistía tras `force-reexecute`. Fix recarga tras
  `clear_invoice_data()`.

## [1.23.1] - 2026-05-01

### Fixed
- Concurrencia del lock por orden: rechazo explícito en lugar de espera
  silenciosa. Nuevo parámetro `$skip_lock` para callers que ya tienen el
  lock tomado.

## [1.23.0] - 2026-05-01

### Added
- Lock por orden alrededor de la emisión (previene doble-envío en
  condiciones de carrera).
- UI guard contra clic doble en el botón de emisión manual.
- Emission log para detectar órdenes huérfanas (clave generada pero sin
  envío a Hacienda).

## [1.22.0] - 2026-05-01

### Added
- Contador atómico de consecutivos (resuelve la race entre emisiones
  simultáneas que producía consecutivos duplicados).
- Pre-flight de validación de IVA antes de firmar.

### Fixed
- `consecutivo`: leer `LAST_INSERT_ID()` vía `SELECT`, no `$wpdb->insert_id`
  (que en HPOS retornaba el ID de la orden, no del contador).
- `preflight`: saltar líneas en cero (`tax_status none` o `subtotal=0`).
- `consecutivo`: paddear cédula a 12 dígitos en counter lookup.

## [1.21.0] - 2026-04-30

### Changed
- Bump de versión (override sobre v1.19.1) para sincronizar tag y header
  tras un release fallido.

## [1.19.1] - 2026-04-30

### Fixed
- Excluir `shop_order_refund` del CLI bulk re-encolado de `reexecute_all`.
- Eliminar `FE_Woo_CABYS_Watcher` (dead code que causaba timeout en
  pantallas de productos con muchos términos).

## [1.19.0] - 2026-04-30

### Added
- T-1: cobertura de tests pure-unit (PaymentMethod, CodigoTarifaIva,
  FacturaGenerator).
- T-3: alerta de certificado próximo a vencer.
- T-4: monitor de cola de facturación.
- T-7: fold-in de patches inline.

## [1.18.0] - 2026-04-28

### Fixed
- PDF-6 watermark TCPDF: subclase de TCPDF + override `_putinfo` Producer
  field + `tcpdflink` deshabilitado + Producer reemplazado en XMP metadata.
- PDF v2 paridad referencia: 6 hallazgos amarillos cerrados (totales nobr,
  1 línea menos en footer, etc.).

## [1.17.0] - 2026-04-28

### Added
- PDF MVP: consecutivo formal, dirección emisor, totales completos, medio
  de pago, autorización.
- PDF fallback a parent emisor cuando faltan dirección/nombre/cedula.

## [1.16.0] - 2026-04-28

### Fixed
- H-5: mapeo `MedioPago` para PowerTranz y FooEvents POS.

## [1.15.0] - 2026-04-27

### Fixed
- H-1: `UnidadMedidaComercial` requerida en líneas de servicio.
- H-2: fallback `Barrio = "Desconocido"` cuando no se puede resolver.

## [1.14.0] - 2026-04-26

### Fixed
- B-4: clave varchar overflow (la columna era VARCHAR(50), insuficiente
  para metadata extendido).
- B-5: email multi-factura bloqueante (un fallo de envío ya no detiene
  el resto).

## [1.13.0] - 2026-04-26

### Fixed
- C-1: duplicación de queue.
- C-2: items varados en estado `processing`.

## [1.12.0] - 2026-04-26

### Changed
- Desacoplar el acuse del envío en queue + multi-factura.

### Fixed
- B-1: nota terminal en cron (los logs de cron no llegaban a la nota de
  la orden).
