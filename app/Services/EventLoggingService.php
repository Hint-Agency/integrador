<?php

namespace App\Services;

use App\Models\Record;
use Exception;

class EventLoggingService
{
    /**
     * @var list<string>
     */
    private const ALLOWED_STATUSES = [
        'init',
        'processing',
        'success',
        'error',
        'warning',
    ];

    public function createEventRecord(
        string $eventType,
        string $status,
        array $payload,
        string $message,
        ?int $parentRecordId = null,
        ?int $eventId = null,
        ?array $details = null
    ): Record {
        $this->assertStatusIsCanonical($status);

        return Record::query()->create([
            'event_id' => $eventId,
            'record_id' => $parentRecordId,
            'event_type' => $eventType,
            'status' => $status,
            'payload' => $payload,
            'message' => $this->translateMessage($message),
            'details' => $details,
        ]);
    }

    public function logEventSuccess(Record $record, string $message): void
    {
        $record->update([
            'status' => 'success',
            'message' => $this->translateMessage($message),
        ]);
    }

    public function logEventError(Record $record, Exception $exception): void
    {
        $existingDetails = is_array($record->details) ? $record->details : [];

        $record->update([
            'status' => 'error',
            'message' => $this->translateMessage($exception->getMessage()),
            'details' => array_merge($existingDetails, [
                'exception' => get_class($exception),
                'code' => $exception->getCode(),
            ]),
        ]);
    }

    public function logEventWarning(Record $record, string $message, array $details = []): void
    {
        $existingDetails = is_array($record->details) ? $record->details : [];

        $record->update([
            'status' => 'warning',
            'message' => $this->translateMessage($message),
            'details' => empty($details) ? $existingDetails : array_replace_recursive($existingDetails, $details),
        ]);
    }

    private function assertStatusIsCanonical(string $status): void
    {
        if (! in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid record status [%s]. Use canonical statuses only.', $status)
            );
        }
    }

    private function translateMessage(string $message): string
    {
        return match ($message) {
            'Webhook not received, platform not found.' => 'Webhook no recibido: no se encontró la plataforma.',
            'Webhook not received, missing secret key or signature configuration.' => 'Webhook no recibido: falta la llave secreta o la configuración de firma.',
            'Webhook not received, invalid signature.' => 'Webhook no recibido: firma inválida.',
            'Webhook received' => 'Webhook recibido.',
            'Invalid payload format' => 'Formato de payload inválido.',
            'Missing subscription type in webhook payload' => 'Falta el tipo de suscripción en el payload del webhook.',
            'No events found for subscription type' => 'No se encontraron eventos para el tipo de suscripción.',
            'Service class not found for event processing.' => 'No se encontró la clase de servicio para procesar el evento.',
            'Event method not available for execution.' => 'El método del evento no está disponible para ejecución.',
            'Event processed with warnings.' => 'Evento procesado con advertencias.',
            'Event processed.' => 'Evento procesado.',
            'Event processing failed.' => 'Falló el procesamiento del evento.',
            'Scheduled event finished with warnings.' => 'Evento programado finalizado con advertencias.',
            'Service class not found for scheduled event.' => 'No se encontró la clase de servicio para el evento programado.',
            'Invalid method name for scheduled event.' => 'Nombre de método inválido para el evento programado.',
            'Write-back completed.' => 'Write-back completado.',
            'Write-back failed.' => 'Falló el write-back.',
            'Write-back skipped because object id or properties are missing.' => 'Write-back omitido porque falta el ID del objeto o las propiedades.',
            'Odoo account.move ignored because it is not an outgoing invoice.' => 'Movimiento account.move de Odoo ignorado porque no es una factura de cliente.',
            'Odoo invoice payload prepared.' => 'Payload de factura Odoo preparado.',
            'Odoo invoice payload prepared with warnings.' => 'Payload de factura Odoo preparado con advertencias.',
            'Failed to create or update HubSpot invoice object.' => 'No se pudo crear o actualizar el objeto de factura en HubSpot.',
            'HubSpot invoice object synchronized.' => 'Objeto de factura sincronizado en HubSpot.',
            'HubSpot invoice object synchronized with warnings.' => 'Objeto de factura sincronizado en HubSpot con advertencias.',
            'Multiple HubSpot invoice objects matched Odoo invoice id.' => 'Más de un objeto de factura HubSpot coincide con el ID de factura Odoo.',
            'Missing Odoo account.move id.' => 'Falta el ID account.move de Odoo.',
            'Odoo account.move was not found.' => 'No se encontró el account.move en Odoo.',
            default => $message,
        };
    }
}
