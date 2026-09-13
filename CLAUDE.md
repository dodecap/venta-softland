# [NOMBRE_PROYECTO]

## Descripción
[Breve descripción: qué hace este proyecto, para qué cliente/empresa, objetivo general]

## Arquitectura

**Repositorio local:** `[ruta o nombre de carpeta]`

**Servidores** (usar siempre el alias SSH, nunca IP/usuario a mano):
- `[alias-ssh-1]` — [rol, ej: "Producción Odoo 18, Ubuntu 22.04, ZeroTier VPN"]
- `[alias-ssh-2]` — [rol, ej: "SQL Server 2012, Windows Server 2012 → migrando a 2022"]

**Bases de datos:**
- Postgres [versión] en `[alias-ssh]` — usada por [sistema]
- SQL Server [versión] en `[alias-ssh]` — usada por [sistema, ej: Soflan]

**Sistemas involucrados:** [Odoo 18 Enterprise, Soflan, etc.]

## Convenciones de trabajo

1. **Antes de empezar cualquier tarea**, lee `STATE.md` para saber en qué quedó el proyecto.
2. **Nunca** escribas contraseñas, IPs con usuario/clave, tokens ni connection strings en archivos versionados (este archivo, STATE.md, docs/). Esa información va solo en `.env` (excluido por `.gitignore`) o en el gestor de contraseñas.
3. Para conectarte a un servidor, usa el alias SSH definido en `~/.ssh/config` de esta máquina (ver `SETUP.md` para configurarlo en un computador nuevo).
4. Al terminar una tarea significativa: **actualiza `STATE.md` y haz commit** con un mensaje descriptivo.
5. Documentación técnica extensa (esquemas de BD, decisiones de arquitectura, notas de migración) va en `docs/`, no en este archivo.
6. Si algo se rompe o queda a medias, anótalo en la sección "Problemas conocidos" de `STATE.md` antes de cerrar la sesión.

## Comandos habituales
- Conectar a `[alias-ssh-1]`: `ssh [alias-ssh-1]`
- [otros comandos típicos: deploy, backup, restart de servicio, etc.]

## Estado actual
Ver `STATE.md` para el avance detallado y las tareas pendientes.
