# Cómo configurar este proyecto en un computador nuevo

1. **Clonar el repositorio**
   ```
   git clone [URL_DEL_REPO]
   cd [NOMBRE_PROYECTO]
   ```

2. **Crear el archivo de variables de entorno**
   ```
   cp .env.example .env
   ```
   Completa `.env` con las credenciales reales (sácalas de tu gestor de contraseñas o notas aparte).

3. **Configurar el acceso SSH a los servidores del proyecto**

   Agrega en `~/.ssh/config` de esta máquina algo como:
   ```
   Host [alias-ssh-1]
       HostName [ip-o-dominio]
       User [usuario]
       Port [puerto, si no es 22]
   ```
   (repite por cada servidor que use este proyecto — ver `CLAUDE.md` para la lista de alias esperados)

4. **Verificar conexión**
   ```
   ssh [alias-ssh-1]
   ```

5. **Leer el estado del proyecto**

   Abre `STATE.md` para ver en qué quedó el trabajo y qué falta.

6. **Empezar a trabajar con Claude Code**

   Dile a Claude: *"Lee CLAUDE.md y STATE.md antes de empezar."*
