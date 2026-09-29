# Alta de coexistencia para el número del asistente

Estado observado en Meta el 29 de septiembre de 2026:

- Número elegido: `+57 301 5232054`, cuenta **Skc Sucasa Inmobiliaria Asistente**.
- ID de cuenta de WhatsApp Business: `4611138722441034`.
- ID de número de teléfono: `1332866283246990`.
- En WhatsApp Manager figura **Sin conexión**. El número no aparece aún en el selector **De** de la configuración de API de la app **Asistente virtual sucasa** (`1443954760961197`).
- El webhook de la app apunta a `https://sucasainmobiliaria.com.co/asistente-sucasa/public/webhook.php`; los campos `messages`, `account_update`, `history`, `smb_app_state_sync` y `smb_message_echoes` aparecen suscritos.
- La verificación del negocio figura aprobada. En el administrador de registro insertado, la revisión de la app y la verificación de acceso siguen pendientes para producción.
- Se creó la configuración **SuCasa WhatsApp coexistencia** de Facebook Login for Business para WhatsApp Embedded Signup, ID `1589704446264976`. Selecciona Cloud API, token de usuario del sistema de 60 días, cuenta de WhatsApp, tareas `VIEW_PHONE_ASSETS`, `MANAGE_PHONE_ASSETS` y `MESSAGING`, y permisos `whatsapp_business_management` y `whatsapp_business_messaging`. Crear esta configuración no conecta el número.
- Se seleccionó la ruta **proveedor de tecnología independiente**. Meta muestra 1 de 2 pasos completados: negocio aprobado y revisión de la app pendiente.
- Para iniciar esa revisión, Meta pide un ícono JPG/GIF/PNG de 512–1024 píxeles y máximo 5 MB, una URL de política de privacidad y una categoría. La política pública existente es `https://sucasainmobiliaria.com.co/politica-privacidad/`; conviene comprobar que cubra el uso de conversaciones de WhatsApp y MiniMax antes de enviarla. El isologo del sitio (`ISOLOGO-WEB.png`) mide 500 × 500 y no alcanza el mínimo del formulario.
- Meta también pide evidencia en video de envío/recepción de un mensaje de prueba y de creación de una plantilla, además de la documentación para solicitar acceso avanzado a `whatsapp_business_messaging` y `whatsapp_business_management`.

## Siguiente secuencia

1. Completar los datos de la app, preparar los videos y enviar la revisión para proveedor de tecnología independiente. Obtener acceso avanzado antes de atender clientes reales.
2. Lanzar Embedded Signup v4 con el ID de configuración anterior y `extras.featureType = "whatsapp_business_app_onboarding"`, elegir **Conectar cuenta existente** y completar en el teléfono el código/QR de verificación. No migrar el número por el flujo estándar si se desea mantener la app móvil.
3. Confirmar con Graph API que `is_on_biz_app` sea `true` y `platform_type` sea `CLOUD_API` para el ID del número. Confirmar que la app móvil sigue activa.
4. Crear un token de usuario del sistema para el servidor, configurar las variables faltantes de `.env` en el alojamiento, comprobar el webhook y ejecutar `php bin/check.php`.
5. Publicar la app según el proceso de Meta, enviar un mensaje de prueba desde un tercero y revisar `suca_messages`, el worker y el ticket en la base de datos.

La [guía oficial de Meta](https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/onboarding-business-app-users) indica que coexistencia requiere un socio de soluciones o proveedor de tecnología. El registro en la app móvil y la selección de compartir el historial requieren acción del titular del número. No pegues tokens ni secretos en chats o capturas.
