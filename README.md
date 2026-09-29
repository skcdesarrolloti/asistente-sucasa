# Asistente SuCasa en PHP

Asistente para mensajes **entrantes** de WhatsApp Business Platform (Cloud API). Usa MiniMax para conversar y extraer intención; PHP controla consultas, asignación y escrituras a las tablas CCT existentes. No requiere n8n, Hermes ni OpenClaw.

## Flujo

1. Meta envía el mensaje al webhook HTTPS. Se valida `X-Hub-Signature-256`, el identificador del número y se guarda una sola vez por ID de mensaje.
2. Un worker cron procesa la cola. MiniMax extrae datos del prospecto.
3. PHP busca únicamente inmuebles publicados por código o filtros. Los datos reales se pasan a MiniMax para redactar la respuesta.
4. Ante interés comercial se registra o reutiliza cliente y ticket; si solicita llamada se crea `wp_jet_cct_cct_llamadas`. Un inmueble concreto dirige al funcionario de `id_funcionario`; si no hay inmueble se usa `ZONE_EMPLOYEES`, luego `DEFAULT_EMPLOYEE_ID`.
5. Si pide atención humana, se pausa la IA 24 horas para ese contacto. No se envían campañas ni mensajes iniciados por el bot.

## Requisitos

- PHP 8.2+ con `pdo_mysql`, `curl`, `mbstring`, `json`; MySQL 8+ (usa `SKIP LOCKED`).
- Acceso SQL a las cinco tablas CCT suministradas y permisos para crear `suca_messages` y `suca_leads`.
- Número habilitado en WhatsApp Business Platform, app Meta con permisos de mensajería, token de acceso permanente, `phone_number_id`, `app_secret` y webhook público HTTPS. Estar en el portafolio comercial por sí solo no habilita mensajes por API. Si quieres seguir usando el mismo número en la app móvil, verifica la opción oficial de coexistencia durante el alta; de lo contrario usa un número distinto.
- API key de MiniMax que pueda llamar el endpoint configurado. MiniMax describe el Token Plan como uso individual e interactivo y recomienda pago por uso para producción; valida tu volumen y la modalidad de facturación antes de activar clientes reales.

## Instalación

```bash
cp .env.example .env
# Editar .env con valores reales; no subirlo al repositorio.
mysql -u usuario -p base_de_datos < sql/001_init.sql
php bin/check.php
```

Configura el document root o una ruta pública para `public/webhook.php`. En Meta, selecciona el campo `messages` del webhook y usa la URL HTTPS y `META_VERIFY_TOKEN` configurados. Protege `src/`, `bin/`, `.env` y `sql/` fuera del document root.

Abrir `public/webhook.php` en el navegador solo comprueba que el archivo PHP responde. El texto «Webhook SuCasa disponible» no confirma credenciales, base de datos ni coexistencia. La verificación real de Meta usa `hub.mode=subscribe`, `hub.verify_token` y `hub.challenge`; después ejecuta `php bin/check.php` en el servidor para comprobar configuración y SQL. Los errores se registran en el log de PHP como `SuCasa webhook: ...`.

Ejecuta el worker cada minuto desde cron:

```cron
* * * * * /usr/bin/php /ruta/asistente-sucasa/bin/worker.php >> /ruta/worker.log 2>&1
```

Rellena `ZONE_EMPLOYEES` con barrios o zonas reales e IDs de funcionarios; el valor del inmueble tiene prioridad. Si ningún funcionario se puede resolver, el ticket queda sin asignar para revisión manual. Configura `DEFAULT_EMPLOYEE_ID` para cubrir esa situación.

## Prueba previa a producción

1. Verifica GET del webhook en Meta.
2. Envía un mensaje de prueba desde un número autorizado por Meta y comprueba una fila `done` en `suca_messages`.
3. Consulta un código publicado y uno inexistente; el asistente solo debe mencionar datos verificados.
4. Pide una llamada y verifica el cliente, ticket, llamada, `id_llamada` y funcionario.
5. Pide un humano; comprueba que los mensajes siguientes queden sin respuesta automática durante la pausa.

Para devolver una conversación a la IA antes de 24 horas: `UPDATE suca_leads SET human_paused_until = NULL WHERE phone = '57XXXXXXXXXX';`.

El esquema CCT puede tener reglas adicionales en JetEngine/WordPress. Antes de producción, valida en una base de pruebas que los estados y campos insertados aparezcan correctamente en tus pantallas. Los medios no textuales todavía requieren derivación manual. La entrega de WhatsApp ante un timeout de red puede quedar ambigua; revisa `suca_messages.error_text` antes de reintentar un fallo para evitar respuestas duplicadas.
