# Instalación local (Windows) — MOTOS2026

Estado de esta PC tras la instalación del 18/11/2026.

## Qué quedó instalado

| Pieza | Detalle |
|---|---|
| Base de datos | PostgreSQL 18.6, puerto **5432** |
| Base | `motos2026` (PostgreSQL la crea en minúsculas) |
| Esquema | `tienda` — 81 tablas, 223 funciones, 86 triggers, 33 vistas |
| Usuario de la app | `admin` (contraseña `20394453`) |
| Dependencias PHP | `vendor/` instalado (`composer install`) |
| Dependencias JS | `node_modules/` + `public/build/` compilado |
| APP_KEY | generada en `.env` |

## Cómo arrancar

Doble clic en **`INICIAR.cmd`**.

Hace tres cosas: levanta PostgreSQL si está parado, abre el navegador y
arranca el servidor en <http://127.0.0.1:8000>.

Si prefieres hacerlo a mano:

```powershell
php artisan serve
```

## Primer arranque

La base está instalada pero **sin configurar**. Al abrir el sistema entra
directo al asistente:

<http://127.0.0.1:8000/registro>

Ahí defines la contraseña del usuario `admin`, la razón social, el RIF, la
dirección fiscal y la tasa del día. Al terminar te manda a `/login`.

> El asistente llama a `sp_primer_admin` y `sp_guardar_configuracion_negocio`;
> hasta que no lo completes, `negocio.configurado_en` es `NULL` y `/login`
> no sirve (el usuario `admin` nace con `password_hash = 'PENDIENTE'`).

## Notas

- **El rol `admin` ya no es superusuario.** El sistema aborta con error 500 si
  la app se conecta como superusuario (`AppServiceProvider`, guarda A-3). Para
  tareas de superusuario usa el rol `postgres` (misma contraseña).
- **El servicio de PostgreSQL está en `Automático` pero detenido** y esta
  cuenta no tiene permisos para arrancarlo. Si quieres que arranque solo con
  Windows: abre `services.msc` como administrador y pulsa *Iniciar* en
  `postgresql-x64-18`. Mientras tanto, `INICIAR.cmd` lo levanta sin permisos
  de administrador.
- `.env` **no** se versiona. Si clonas el repo en otro equipo, copia
  `.env.example` a `.env`, ajusta `DB_*` y ejecuta `php artisan key:generate`.
- El esquema no usa migraciones de Laravel: se instala con los archivos de
  `database/sql/` y se precargan catálogos con `php artisan db:seed`.

## Modo desarrollo (cambios sin recargar)

```powershell
npm run dev
```

Vite sirve los assets en <http://127.0.0.1:5173> y escribe el archivo
`public/hot`. Laravel lo detecta y usa ese servidor: los cambios se aplican en
caliente, sin recargar ni recompilar.

- Mientras Vite corre, `public/hot` **debe existir**. Si cierras Vite, borra el
  archivo (`Remove-Item public\hot`) o el navegador seguirá buscando el puerto
  5173 y la página saldrá en blanco.
- Para volver al modo normal (lo que usa `INICIAR.cmd`): `npm run build` y
  comprobar que no quede `public\hot`.

