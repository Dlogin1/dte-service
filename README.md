# dte-service — emisión de DTE (SII Chile) para clari.cl

Wrapper delgado sobre [LibreDTE lib-core](https://github.com/LibreDTE/libredte-lib-core)
que corre como funciones PHP en Vercel (runtime comunitario
[`vercel-community/php`](https://github.com/vercel-community/php)). clari (Node) es su
único cliente y le habla por HTTP con `Authorization: Bearer <DTE_SERVICE_TOKEN>`.

La especificación completa vive en el repo de clari:
`docs/PROMPT_FACTURACION_ELECTRONICA.md`. Este servicio es **sin estado**: los folios
viven en Supabase (los administra clari) y el certificado llega por env var.

> **Este repo es PÚBLICO a propósito.** LibreDTE es AGPL: el código que la usa y se
> expone por red debe publicar su fuente. Por eso este wrapper vive separado del código
> privado de clari, y por eso **jamás** puede haber secretos, certificados ni CAF acá.

## Endpoints

| Ruta | Estado | Qué hace |
|---|---|---|
| `GET /api/salud` | **Fase 0** | Diagnóstico del build: PHP, extensiones, versión+commit de LibreDTE, guard de ambiente. Público (no expone secretos). |
| `POST /api/emitir` | stub 501 | Fase 1: genera, timbra y envía el DTE; devuelve XML + datos del timbre. **No genera PDF** (eso lo hace clari con Chrome headless). |
| `POST /api/estado` | stub 501 | Fase 1: consulta el estado de un track ID en el SII. |

## Variables de entorno (Vercel → Settings → Environment Variables)

| Variable | Fase 0 | Nota |
|---|---|---|
| `SII_AMBIENTE` | `certificacion` | Guard fail-closed: sin ella el servicio no opera. `produccion` además exige `SII_PRODUCCION_CONFIRMADA=si` (regla dura §10.1: NO configurar sin autorización explícita de Diego). |
| `DTE_SERVICE_TOKEN` | un secreto largo aleatorio | El mismo valor va en el proyecto de clari. Generar con `openssl rand -hex 32`. |
| `CERT_P12_BASE64` + `CERT_PASS` | Fase 1 | Certificado digital de certificación, en base64. Jamás en el repo. |

## Deploy (Fase 0)

1. Crear el repo **público** `dte-service` en GitHub y publicar esta carpeta
   (GitHub Desktop → Add Local Repository → Publish).
2. Correr la Action **«Congelar dependencias»** (Actions → Run workflow): genera y
   commitea `composer.lock`. Sin esto el build resuelve ramas vivas (`dev-master` +
   `dev-main`) y no es reproducible.
3. En Vercel: **Add New Project** → importar `dte-service` → configurar las env vars
   de Fase 0 → Deploy.
4. Abrir `https://<proyecto>.vercel.app/api/salud` y verificar `"ok": true`.
   - Si `faltan_requeridas` no está vacío o el build no compila → **plan B** del
     prompt (§4.2): contenedor en Google Cloud Run o Koyeb.

## Decisiones de build que no hay que "arreglar"

- **`dev-master` sin tag**: el lib-core nuevo (PHP 8.5) no tiene release etiquetado;
  la reproducibilidad la da `composer.lock` commiteado (Action de arriba).
- **`config.platform.ext-gd`** en `composer.json`: Vercel no trae `gd` y alguna
  dependencia de PDF puede exigirla. Se declara como presente para que composer
  resuelva, porque **el camino de PDF de LibreDTE no se usa**: el PDF lo genera clari.
  `platform-check: false` evita que el bootstrap de vendor rechace el runtime por lo mismo.
