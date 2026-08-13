# sodimac-ws

Web Service REST para la aplicacion movil de inventario Sodimac/Accumin.

## Estructura del proyecto

```
sodimac-ws/
├── api/
│   ├── auth/
│   │   ├── login.php                  # Login real contra BD
│   │   └── login_mockup.php           # Login mockup (sin BD)
│   └── sincronizaciones/
│       ├── index.php                  # Registro de sincronizaciones
│       ├── preparacion.php            # Preparacion real (contrato APK V6)
│       ├── preparacion_mockup.php     # Preparacion mockup (sin BD)
│       ├── preparacion_ws.php         # Preparacion mixta (usuario real + datos mock)
│       └── tag-finalizado.php         # Recepcion de tag desde PDA
├── config/
│   ├── database.php                   # [IGNORADO] Credenciales reales
│   ├── database.example.php           # Plantilla de credenciales
│   ├── acceso_sodimac_db.php          # [IGNORADO] Clase PDO con credenciales
│   └── acceso_sodimac_db.example.php  # Plantilla de la clase PDO
├── helpers/
│   └── response.php                   # Funciones auxiliares JSON/CORS
├── tools/
│   ├── verificar_usuario_preparacion.php
│   └── diagnostico_preparacion_fecha.php
├── index.php                          # [IGNORADO] Servidor SOAP (no va al repo)
├── .gitignore
└── README.md
```

## Endpoints REST principales

| Metodo | Endpoint | Descripcion |
|--------|----------|-------------|
| POST | `/api/auth/login.php` | Login de usuario |
| POST | `/api/auth/login_mockup.php` | Login mockup (desarrollo) |
| POST | `/api/sincronizaciones/preparacion.php` | Preparacion sincronizacion APK |
| POST | `/api/sincronizaciones/preparacion_mockup.php` | Preparacion mockup |
| POST | `/api/sincronizaciones/preparacion_ws.php` | Preparacion mixta |
| POST | `/api/sincronizaciones/tag-finalizado.php` | Recepcion tag PDA |
| POST | `/api/sincronizaciones/index.php` | Registro sincronizacion |

## Configuracion local

1. Copiar los archivos de ejemplo:

```bash
cp config/database.example.php config/database.php
cp config/acceso_sodimac_db.example.php config/acceso_sodimac_db.php
```

2. Editar los archivos copiados con tus credenciales reales.

3. Asegurar que PHP tenga habilitado el driver `mysql` de PDO.

## Notas

- Este repo es **privado**.
- Los archivos `tools/` y `mockups` quedan versionados.
- `config/database.php`, `config/acceso_sodimac_db.php` e `index.php` (SOAP) **no van al repo**.
- El servidor SOAP (`index.php` raiz) se mantiene local pero no se versiona.
- tag-finalizado.php integra PRC_SOD_PDA_CAPTURA_REGISTRAR_V1 para iteracion = 1, llamando el SP una vez por detalle.

## Estados tag-finalizado

`tag-finalizado.php` guarda toda carga recibida en `sod_pda_tag_carga` y su detalle en `sod_pda_tag_carga_detalle`.

| Estado | Uso |
|--------|-----|
| `RECIBIDO` | Payload guardado localmente. Para `iteracion > 1` queda en este estado porque reconteo SGO no esta integrado aun. |
| `PROCESADO` | Payload de `iteracion = 1` guardado y enviado correctamente a `PRC_SOD_PDA_CAPTURA_REGISTRAR_V1` por cada detalle. |
| `ERROR` | Estado reservado para fallas persistidas. En la implementacion actual, las fallas criticas hacen rollback y responden `ERROR`, por lo que no siempre queda una fila con este estado. |

Para `iteracion = 1`, el endpoint valida `numero_agenda`, TAG numerico, `detalle_uid`, codigo usable y `fecha_hora` antes de llamar el SP.
El campo `detalle_uid` se usa como `p_id_origen_externo`.
El campo `zona_nombre` se usa como `p_codigo_ubicacion`.

## Validacion PHP

```bash
php -l api/auth/login.php
php -l api/sincronizaciones/preparacion.php
php -l api/sincronizaciones/tag-finalizado.php
php -l helpers/response.php
php -l config/database.example.php
php -l config/acceso_sodimac_db.example.php
```
