-- =============================================================================
--  Archivo 44 · TOUR DE BIENVENIDA (onboarding interactivo)
-- =============================================================================
--  Qué agrega:
--    · tour_completado en usuarios → marca si la persona ya vio (o cerró) la
--      guía de bienvenida «Conoce SmartMotos». El primer login de un usuario
--      nuevo muestra la pantalla de bienvenida en lugar del panel y, al terminar
--      o cerrar el tour, el sistema marca la columna TRUE para no volver a
--      interrumpirlo. Desde el menú puede relanzar la guía cuando quiera
--      («Ver guía de nuevo»), sin que esto cambie el arranque diario.
--
--  No toca datos fiscales ni ventas. Es idempotente.
-- =============================================================================

SET search_path = tienda, public;

ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS tour_completado BOOLEAN NOT NULL DEFAULT FALSE;

COMMENT ON COLUMN usuarios.tour_completado IS 'TRUE = ya vio la guía de bienvenida (o la cerró). FALSE = al entrar se le muestra la pantalla de bienvenida y el tour «Conoce SmartMotos». Relanzar la guía desde el menú no cambia este valor.';
