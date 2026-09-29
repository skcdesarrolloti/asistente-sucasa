<?php
declare(strict_types=1);

final class Repository
{
    public function __construct(private PDO $db, private array $config) {}

    public function enqueue(string $id, string $phone, string $body, int $userTimestamp): void
    {
        $stmt = $this->db->prepare('INSERT IGNORE INTO suca_messages (wa_message_id, phone, body, user_timestamp) VALUES (?, ?, ?, ?)');
        $stmt->execute([$id, $phone, $body, $userTimestamp]);
    }

    public function claim(): ?array
    {
        // A crashed worker can leave a row in processing; retry it after five minutes.
        $this->db->exec("UPDATE suca_messages SET status = 'pending' WHERE status = 'processing' AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE) AND attempts < 3");
        $this->db->exec("UPDATE suca_messages SET status = 'failed', error_text = 'Worker interrupted' WHERE status = 'processing' AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE) AND attempts >= 3");
        $this->db->beginTransaction();
        try {
            $row = $this->db->query("SELECT * FROM suca_messages WHERE status = 'pending' AND next_attempt_at <= NOW() ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED")->fetch();
            if ($row) {
                $stmt = $this->db->prepare("UPDATE suca_messages SET status = 'processing', attempts = attempts + 1 WHERE id = ?");
                $stmt->execute([$row['id']]);
            }
            $this->db->commit();
            return $row ?: null;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function finish(int $id, ?string $reply = null): void
    {
        $this->db->prepare("UPDATE suca_messages SET status = 'done', reply_body = ?, error_text = NULL WHERE id = ?")->execute([$reply, $id]);
    }

    public function storeReply(int $id, string $reply): void
    {
        $this->db->prepare('UPDATE suca_messages SET reply_body = ? WHERE id = ?')->execute([$reply, $id]);
    }

    public function fail(int $id, int $attempts, string $error): void
    {
        $status = $attempts >= 3 ? 'failed' : 'pending';
        $delay = min(20, $attempts * $attempts);
        $this->db->prepare('UPDATE suca_messages SET status = ?, error_text = ?, next_attempt_at = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?')
            ->execute([$status, mb_substr($error, 0, 500), $delay, $id]);
    }

    public function history(string $phone): array
    {
        $stmt = $this->db->prepare("SELECT body, reply_body FROM suca_messages WHERE phone = ? AND status = 'done' ORDER BY id DESC LIMIT 4");
        $stmt->execute([$phone]);
        $rows = array_reverse($stmt->fetchAll());
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['role' => 'user', 'body' => $row['body']];
            if ($row['reply_body']) $out[] = ['role' => 'assistant', 'body' => $row['reply_body']];
        }
        return $out;
    }

    public function lead(string $phone): array
    {
        $stmt = $this->db->prepare('SELECT * FROM suca_leads WHERE phone = ?');
        $stmt->execute([$phone]);
        $row = $stmt->fetch();
        if (!$row) return ['phone' => $phone, 'profile' => [], 'client_id' => null, 'ticket_id' => null, 'call_id' => null, 'human_paused_until' => null];
        $row['profile'] = json_decode($row['profile_json'], true) ?: [];
        return $row;
    }

    public function rememberProfile(string $phone, array $profile): void
    {
        $json = json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->db->prepare('INSERT INTO suca_leads (phone, profile_json) VALUES (?, ?) ON DUPLICATE KEY UPDATE profile_json = VALUES(profile_json)')
            ->execute([$phone, $json]);
    }

    public function blocked(string $phone): bool
    {
        $local = str_starts_with($phone, '57') && strlen($phone) === 12 ? substr($phone, 2) : $phone;
        $stmt = $this->db->prepare("SELECT bloqueo_whatsapp, permite_solicitud_whatsapp FROM wp_jet_cct_clientes WHERE REPLACE(REPLACE(celular, '+', ''), ' ', '') IN (?, ?) ORDER BY _ID DESC LIMIT 1");
        $stmt->execute([$phone, $local]);
        $row = $stmt->fetch();
        return $row && ((int) $row['bloqueo_whatsapp'] === 1 || (int) $row['permite_solicitud_whatsapp'] === 0);
    }

    public function propertyByCode(string $code): ?array
    {
        if ($code === '') return null;
        $stmt = $this->db->prepare("SELECT _ID, codigo, tipo_inmueble, tipo_negocio, destinacion, barrio, ciudad, precio_arriendo, precio_venta, habitaciones, banos, estado, id_funcionario FROM wp_jet_cct_inmuebles WHERE codigo = ? AND LOWER(TRIM(COALESCE(cct_status, ''))) IN ('','publish','published') AND LOWER(TRIM(estado)) IN ('publico','publicado') LIMIT 1");
        $stmt->execute([$code]);
        return $stmt->fetch() ?: null;
    }

    public function searchProperties(array $profile): array
    {
        if (empty($profile['business']) && empty($profile['property_type']) && empty($profile['zone'])) return [];
        $sql = "SELECT _ID, codigo, tipo_inmueble, tipo_negocio, destinacion, barrio, ciudad, precio_arriendo, precio_venta, habitaciones, banos, estado, id_funcionario FROM wp_jet_cct_inmuebles WHERE LOWER(TRIM(COALESCE(cct_status, ''))) IN ('','publish','published') AND LOWER(TRIM(estado)) IN ('publico','publicado')";
        $params = [];
        if (!empty($profile['business'])) { $sql .= ' AND tipo_negocio LIKE ?'; $params[] = '%' . $profile['business'] . '%'; }
        if (!empty($profile['property_type'])) { $sql .= ' AND tipo_inmueble LIKE ?'; $params[] = '%' . $profile['property_type'] . '%'; }
        if (!empty($profile['zone'])) { $sql .= ' AND (barrio LIKE ? OR ciudad LIKE ?)'; $params[] = '%' . $profile['zone'] . '%'; $params[] = '%' . $profile['zone'] . '%'; }
        $sql .= ' ORDER BY _ID DESC LIMIT 30';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        if (!empty($profile['budget'])) {
            $field = ($profile['business'] ?? '') === 'venta' ? 'precio_venta' : 'precio_arriendo';
            $rows = array_values(array_filter($rows, static function ($row) use ($field, $profile) {
                $price = (int) (preg_replace('/\D/', '', (string) $row[$field]) ?: 0);
                return $price > 0 && $price <= (int) $profile['budget'];
            }));
        }
        return array_slice($rows, 0, 3);
    }

    public function saveProspect(string $phone, string $message, array $profile, ?array $property, bool $wantsCall, string $waMessageId): array
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare('INSERT INTO suca_leads (phone, profile_json) VALUES (?, ?) ON DUPLICATE KEY UPDATE phone = VALUES(phone)')
                ->execute([$phone, '{}']);
            $stmt = $this->db->prepare('SELECT * FROM suca_leads WHERE phone = ? FOR UPDATE');
            $stmt->execute([$phone]);
            $lead = $stmt->fetch();
            $old = json_decode($lead['profile_json'], true) ?: [];
            $profile = array_merge($old, array_filter($profile, static fn($value) => $value !== '' && $value !== null && $value !== false));
            if ($lead['last_wa_message_id'] === $waMessageId && $lead['ticket_id']) {
                $this->db->commit();
                return ['client_id' => (int) $lead['client_id'], 'ticket_id' => (int) $lead['ticket_id'],
                    'call_id' => $lead['call_id'] ? (int) $lead['call_id'] : null, 'profile' => $profile];
            }
            $clientId = $lead['client_id'] ?: $this->upsertClient($phone, $profile);
            $employee = $this->employee($property, $profile);
            $description = 'Prospecto WhatsApp IA. Mensaje: ' . mb_substr($message, 0, 1500) . "\nPerfil: " . json_encode($profile, JSON_UNESCAPED_UNICODE);
            $ticketId = $lead['ticket_id'] ?: $this->insertTicket($phone, $clientId, $employee, $description, $profile, $property);
            if ($lead['ticket_id']) $this->updateTicket((int) $ticketId, $description, $employee, $profile, $property);
            $callId = $lead['call_id'];
            if ($wantsCall && !$callId) $callId = $this->insertCall($phone, $clientId, $ticketId, $employee, $profile, $property, $message);
            $stmt = $this->db->prepare('UPDATE suca_leads SET profile_json = ?, client_id = ?, ticket_id = ?, call_id = ?, last_wa_message_id = ? WHERE id = ?');
            $stmt->execute([json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $clientId, $ticketId, $callId, $waMessageId, $lead['id']]);
            $this->db->commit();
            return ['client_id' => (int) $clientId, 'ticket_id' => (int) $ticketId, 'call_id' => $callId ? (int) $callId : null, 'profile' => $profile];
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function pauseHuman(string $phone, int $hours = 24): void
    {
        $hours = max(1, min(72, $hours));
        $this->db->prepare('INSERT INTO suca_leads (phone, profile_json, human_paused_until) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR)) ON DUPLICATE KEY UPDATE human_paused_until = VALUES(human_paused_until)')
            ->execute([$phone, '{}', $hours]);
    }

    private function upsertClient(string $phone, array $profile): int
    {
        $local = str_starts_with($phone, '57') && strlen($phone) === 12 ? substr($phone, 2) : $phone;
        $stmt = $this->db->prepare("SELECT _ID FROM wp_jet_cct_clientes WHERE REPLACE(REPLACE(celular, '+', ''), ' ', '') IN (?, ?) ORDER BY _ID DESC LIMIT 1");
        $stmt->execute([$phone, $local]);
        $id = $stmt->fetchColumn();
        if ($id) {
            if (!empty($profile['name']) || !empty($profile['email'])) {
                $this->db->prepare("UPDATE wp_jet_cct_clientes SET nombre = CASE WHEN ? <> '' THEN ? ELSE nombre END, correo = CASE WHEN ? <> '' THEN ? ELSE correo END, cct_modified = NOW() WHERE _ID = ?")
                    ->execute([$profile['name'] ?? '', $profile['name'] ?? '', $profile['email'] ?? '', $profile['email'] ?? '', $id]);
            }
            return (int) $id;
        }
        $this->db->prepare("INSERT INTO wp_jet_cct_clientes (cct_status, cct_created, cct_modified, nombre, indicativo, celular, correo, tipo_cliente) VALUES ('publish', NOW(), NOW(), ?, ?, ?, ?, 'Prospecto WhatsApp IA')")
            ->execute([$profile['name'] ?? 'Cliente WhatsApp ' . $local, str_starts_with($phone, '57') ? '+57' : '', $local, $profile['email'] ?? '']);
        $id = (int) $this->db->lastInsertId();
        $this->db->prepare('UPDATE wp_jet_cct_clientes SET id_cliente = ? WHERE _ID = ?')->execute([(string) $id, $id]);
        return $id;
    }

    private function employee(?array $property, array $profile): ?array
    {
        $candidates = [trim((string) ($property['id_funcionario'] ?? ''))];
        foreach ($this->config['zone_employees'] as $zone => $candidate) {
            if ($zone !== '' && mb_stripos((string) ($profile['zone'] ?? ''), (string) $zone) !== false) {
                $candidates[] = (string) $candidate;
                break;
            }
        }
        $candidates[] = $this->config['default_employee_id'];
        $stmt = $this->db->prepare('SELECT _ID, id_empleado, nombre, correo, celular, activo FROM wp_jet_cct_funcionarios WHERE _ID = ? OR id_empleado = ? LIMIT 1');
        foreach (array_unique(array_filter($candidates)) as $id) {
            $stmt->execute([$id, $id]);
            $row = $stmt->fetch();
            if ($row && !in_array(mb_strtolower(trim((string) $row['activo'])), ['no', '0', 'inactivo'], true)) return $row;
        }
        return null;
    }

    private function insertTicket(string $phone, int $clientId, ?array $employee, string $description, array $profile, ?array $property): int
    {
        $stmt = $this->db->prepare("INSERT INTO wp_jet_cct_tickets (cct_status, cct_created, cct_modified, asunto, descripcion, id_cliente, id_solicitante, solicitante, correo_solicitante, celular_solicitante, celular_wsp, id_inmueble, inmueble, id_empleado, empleado, nombre_empleado, correo_empleado, celular_empleado, barrio, tipo_inmueble, presupuesto, medio, departamento, estado, estado_comercial, tema_ayuda, fecha, fecha_actualizacion) VALUES ('publish', NOW(), NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'WhatsApp IA', 'Comercial', 'Nuevo', 'Nuevo', ?, ?, ?)");
        $now = time();
        $stmt->execute([
            $property ? 'Interés inmueble ' . $property['codigo'] : 'Prospecto comercial WhatsApp IA', $description,
            (string) $clientId, (string) $clientId, $profile['name'] ?? 'Cliente WhatsApp', $profile['email'] ?? '', $phone, $phone,
            $property['_ID'] ?? '', $property['codigo'] ?? '', $employee['_ID'] ?? '', $employee['nombre'] ?? '', $employee['nombre'] ?? '',
            $employee['correo'] ?? '', $employee['celular'] ?? '', $profile['zone'] ?? '', $profile['property_type'] ?? '',
            $profile['budget'] ?? '', $profile['business'] ?? 'Consulta comercial', $now, $now,
        ]);
        $id = (int) $this->db->lastInsertId();
        $this->db->prepare('UPDATE wp_jet_cct_tickets SET id_ticket = ? WHERE _ID = ?')->execute([(string) $id, $id]);
        return $id;
    }

    private function insertCall(string $phone, int $clientId, int $ticketId, ?array $employee, array $profile, ?array $property, string $message): int
    {
        $stmt = $this->db->prepare("INSERT INTO wp_jet_cct_cct_llamadas (cct_status, id_ticket, id_cliente, id_inmueble, id_empleado, cliente, celular, celular_wsp, email, medio, tema_ayuda, observacion, descripcion, fecha) VALUES ('publish', ?, ?, ?, ?, ?, ?, ?, ?, 'WhatsApp IA', 'Llamada comercial', ?, ?, ?)");
        $stmt->execute([(string) $ticketId, (string) $clientId, $property['_ID'] ?? '', $employee['_ID'] ?? '', $profile['name'] ?? 'Cliente WhatsApp', $phone, $phone, $profile['email'] ?? '', 'Solicita llamada', mb_substr($message, 0, 1500), time()]);
        $id = (int) $this->db->lastInsertId();
        $this->db->prepare('UPDATE wp_jet_cct_tickets SET id_llamada = ? WHERE _ID = ?')->execute([(string) $id, $ticketId]);
        return $id;
    }

    private function updateTicket(int $ticketId, string $description, ?array $employee, array $profile, ?array $property): void
    {
        $stmt = $this->db->prepare("UPDATE wp_jet_cct_tickets SET descripcion = CONCAT(COALESCE(descripcion, ''), ?, ?), cct_modified = NOW(), fecha_actualizacion = ?, solicitante = ?, correo_solicitante = ?, barrio = ?, tipo_inmueble = ?, presupuesto = ?, id_inmueble = CASE WHEN ? <> '' THEN ? ELSE id_inmueble END, inmueble = CASE WHEN ? <> '' THEN ? ELSE inmueble END, id_empleado = CASE WHEN ? <> '' THEN ? ELSE id_empleado END, empleado = CASE WHEN ? <> '' THEN ? ELSE empleado END, nombre_empleado = CASE WHEN ? <> '' THEN ? ELSE nombre_empleado END, correo_empleado = CASE WHEN ? <> '' THEN ? ELSE correo_empleado END, celular_empleado = CASE WHEN ? <> '' THEN ? ELSE celular_empleado END WHERE _ID = ?");
        $propertyId = (string) ($property['_ID'] ?? '');
        $propertyCode = (string) ($property['codigo'] ?? '');
        $employeeId = (string) ($employee['_ID'] ?? '');
        $employeeName = (string) ($employee['nombre'] ?? '');
        $employeeEmail = (string) ($employee['correo'] ?? '');
        $employeePhone = (string) ($employee['celular'] ?? '');
        $stmt->execute(["\n\n", $description, time(), $profile['name'] ?? 'Cliente WhatsApp', $profile['email'] ?? '',
            $profile['zone'] ?? '', $profile['property_type'] ?? '', $profile['budget'] ?? '',
            $propertyId, $propertyId, $propertyCode, $propertyCode,
            $employeeId, $employeeId, $employeeName, $employeeName, $employeeName, $employeeName,
            $employeeEmail, $employeeEmail, $employeePhone, $employeePhone, $ticketId]);
    }
}
