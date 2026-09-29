# Alta de coexistencia para el número del asistente

Estado observado en Meta el 29 de septiembre de 2026:

- Número elegido: `+57 301 5232054`, cuenta **Skc Sucasa Inmobiliaria Asistente**.
- ID de cuenta de WhatsApp Business: `4611138722441034`.
- ID de número de teléfono: `1332866283246990`.
- En WhatsApp Manager figura **Sin conexión**. El número no aparece aún en el selector **De** de la configuración de API de la app **Asistente virtual sucasa** (`1443954760961197`).
- El webhook de la app apunta a `https://sucasainmobiliaria.com.co/asistente-sucasa/public/webhook.php`; los campos `messages`, `account_update`, `history`, `smb_app_state_sync` y `smb_message_echoes` aparecen suscritos.
- La verificación del negocio figura aprobada. En el administrador de registro insertado, la revisión de la app y la verificación de acceso siguen pendientes para producción. Todavía no hay una configuración de Facebook Login for Business para WhatsApp Embedded Signup.

## Siguiente secuencia

1. Crear una configuración de Facebook Login for Business con variación **Registro insertado de WhatsApp**, producto **WhatsApp Cloud API**, token de usuario del sistema, cuenta de WhatsApp y permisos `whatsapp_business_management` y `whatsapp_business_messaging`.
2. Completar el registro como proveedor de tecnología o elegir un socio de soluciones habilitado. Completar revisión de la app y verificación de acceso antes de atender clientes reales.
3. Lanzar Embedded Signup v4 con `extras.featureType = "whatsapp_business_app_onboarding"`, elegir **Conectar cuenta existente** y completar en el teléfono el código/QR de verificación. No migrar el número por el flujo estándar si se desea mantener la app móvil.
4. Confirmar con Graph API que `is_on_biz_app` sea `true` y `platform_type` sea `CLOUD_API` para el ID del número. Confirmar que la app móvil sigue activa.
5. Crear un token de usuario del sistema para el servidor, configurar las variables faltantes de `.env` en el alojamiento, comprobar el webhook y ejecutar `php bin/check.php`.
6. Publicar la app según el proceso de Meta, enviar un mensaje de prueba desde un tercero y revisar `suca_messages`, el worker y el ticket en la base de datos.

La [guía oficial de Meta](https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/onboarding-business-app-users) indica que coexistencia requiere un socio de soluciones o proveedor de tecnología. El registro en la app móvil y la selección de compartir el historial requieren acción del titular del número. No pegues tokens ni secretos en chats o capturas.
