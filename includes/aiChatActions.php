<?php
declare(strict_types=1);

/**
 * Central allow-list for AI-assisted mutations. New operations must be added here,
 * to aiChatValidateAction, and to the front-end executor only after the existing
 * module's permissions, ownership and validations have been reviewed.
 *
 * In particular, update/delete/status are NOT enabled just because an AI model
 * emits an action name. They need individually vetted domain adapters.
 */
function aiChatActionDefinitions(): array
{
    return [
        'create_client' => ['label' => 'Client', 'permission' => 'clients.create', 'event' => 'created'],
        'create_tender' => ['label' => 'Tender', 'permission' => 'tenders.create', 'event' => 'created'],
        'create_keyperson' => ['label' => 'Key Person', 'permission' => 'keypersons.create', 'event' => 'created'],
        'create_gift_list' => ['label' => 'Gift List', 'permission' => 'gift_lists.create', 'event' => 'created'],
    ];
}

function aiChatCanCreateGiftList(mysqli $conn, array $user): bool
{
    // The existing route grants create OR edit. Retain exactly that rule.
    return userHasPermission($conn, $user, 'gift_lists.create')
        || userHasPermission($conn, $user, 'gift_lists.edit');
}

function aiChatActionPermission(mysqli $conn, array $user, string $type): void
{
    $definitions = aiChatActionDefinitions();
    if (!isset($definitions[$type])) {
        throw new RuntimeException('This AI action is not supported.', 422);
    }
    if ($type === 'create_gift_list') {
        if (!aiChatCanCreateGiftList($conn, $user)) {
            throw new RuntimeException('You are not authorised to create a gift list.', 403);
        }
    } else {
        requirePermission($conn, $user, $definitions[$type]['permission']);
    }
}

/** Compare only server-verified records with the approved draft; never trust the AI or a client-supplied "success" flag. */
function aiChatVerifiedCreatedRecord(mysqli $conn, array $user, string $type, array $draft, int $id, array $review): array
{
    aiChatActionPermission($conn, $user, $type);
    $userId = (int) $user['id'];
    if ($type === 'create_client') {
        $record = dbFetchOne($conn, 'SELECT id,clients_name,clients_category,clients_hq_location FROM clients_table WHERE id=? AND created_by_id=?', 'ii', [$id, $userId]);
        $name = trim((string) ($record['clients_name'] ?? ''));
        $expected = trim((string) ($draft['clients_name'] ?? ''));
    } elseif ($type === 'create_tender') {
        $record = dbFetchOne($conn, 'SELECT code,project_title,division,project_country,project_city,currency FROM project_info_table WHERE code=? AND created_by_id=?', 'ii', [$id, $userId]);
        $name = trim((string) ($record['project_title'] ?? ''));
        $expected = trim((string) ($draft['project_title'] ?? ''));
    } elseif ($type === 'create_keyperson') {
        $record = dbFetchOne($conn, 'SELECT id,key_person,clients_id FROM keypersons_table WHERE id=? AND created_by_id=?', 'ii', [$id, $userId]);
        $name = trim((string) ($record['key_person'] ?? ''));
        $expected = trim((string) ($draft['key_person'] ?? ''));
        if ($record && (int) ($review['clients_id'] ?? 0) !== (int) $record['clients_id']) {
            throw new RuntimeException('The created Key Person does not belong to the reviewed client.', 409);
        }
    } elseif ($type === 'create_gift_list') {
        $record = dbFetchOne($conn, 'SELECT id,gift_year,owner_pms_admin_id FROM gift_lists WHERE id=? AND created_by_id=?', 'ii', [$id, $userId]);
        // The review may explicitly change the AI-suggested year. Verify what
        // the human approved, not the untrusted model's original suggestion.
        $expectedYear = (int) ($review['gift_year'] ?? $draft['gift_year'] ?? 0);
        if (!$record || $expectedYear !== (int) $record['gift_year']) {
            throw new RuntimeException('The created gift list does not match the reviewed year.', 409);
        }
        if ((int) ($review['owner_pms_admin_id'] ?? 0) !== (int) $record['owner_pms_admin_id']) {
            throw new RuntimeException('The gift-list owner does not match the reviewed selection.', 409);
        }
        $approvedRows = $review['items'] ?? null;
        if (!is_array($approvedRows) || !$approvedRows || count($approvedRows) > 10
            || count($approvedRows) !== count($draft['items'] ?? [])) {
            throw new RuntimeException('Missing or invalid reviewed gift-list recipients.', 409);
        }
        $savedRows = dbFetchAll($conn, 'SELECT client_id,keyperson_id,gift_rate FROM gift_list_items WHERE gift_list_id=?', 'i', [$id]);
        if (count($savedRows) !== count($approvedRows)) {
            throw new RuntimeException('Saved gift-list recipients differ from the review.', 409);
        }
        $saved = [];
        foreach ($savedRows as $row) {
            $key = (int) $row['keyperson_id'];
            $saved[$key] = [(int) $row['client_id'], (string) $row['gift_rate']];
        }
        foreach ($approvedRows as $index => $row) {
            if (!is_array($row)) throw new RuntimeException('Invalid reviewed recipient.', 409);
            $key = (int) ($row['keyperson_id'] ?? 0);
            $client = (int) ($row['client_id'] ?? 0);
            $rate = (string) ($row['gift_rate'] ?? '');
            if ($key <= 0 || $client <= 0 || !$rate || !isset($saved[$key])
                || $saved[$key] !== [$client, $rate]
                || !in_array($rate, ['A+', 'A', 'B+', 'B', 'C+', 'C', 'D'], true)) {
                throw new RuntimeException('Saved gift-list recipients or rates differ from the review.', 409);
            }
            unset($saved[$key]);
        }
        if ($saved) throw new RuntimeException('Unexpected recipients were saved.', 409);
        return ['label' => 'Gift List', 'name' => $expectedYear.' annual gift list', 'id' => $id, 'type' => $type, 'event' => 'created'];
    } else {
        throw new RuntimeException('This AI action is not supported.', 422);
    }
    // Compare reviewed select values to the saved record, when supplied by the
    // enhanced review UI. Older pending confirmations without these values
    // remain recoverable using the pre-existing verification path.
    if ($record && $type === 'create_client') {
        // Country/city are input fields composed into clients_address;
        // clients_table does NOT have dedicated country/city columns.
        if (array_key_exists('clients_category', $review)
            && (string) $review['clients_category'] !== (string) $record['clients_category']) {
            throw new RuntimeException('Saved client category does not match the approval.', 409);
        }
        if (!empty($review['clients_city'])
            && (string) $review['clients_city'] !== (string) $record['clients_hq_location']) {
            throw new RuntimeException('Saved client location does not match the approval.', 409);
        }
    }
    if ($record && $type === 'create_tender') {
        foreach (['division', 'project_country', 'project_city', 'currency'] as $column) {
            if (array_key_exists($column, $review)
                && (string) $review[$column] !== (string) ($record[$column] ?? '')) {
                throw new RuntimeException('Saved tender details do not match the approved selections.', 409);
            }
        }
        if (isset($review['clients'])) {
            $approved = $review['clients'];
            if (!is_array($approved) || !$approved || count($approved) > 10
                || count($approved) !== count($draft['clients'] ?? [])) {
                throw new RuntimeException('Missing approved tender relationships.', 409);
            }
            $savedRows = dbFetchAll($conn,
                "SELECT clients_id,clients_name,keyperson FROM clients_keypersons_table WHERE project_id=? AND record_status='active'",
                'i', [$id]);
            if (count($savedRows) !== count($approved)) {
                throw new RuntimeException('Saved tender relationships differ from the approved selection.', 409);
            }
            foreach ($approved as $at => $linked) {
                if (!is_array($linked) || (int) ($linked['client_id'] ?? 0) < 1
                    || (int) ($linked['keyperson_id'] ?? 0) < 1) {
                    throw new RuntimeException('Invalid reviewed tender relationship.', 409);
                }
                $matched = false;
                foreach ($savedRows as $key => $saved) {
                    if ((int) $saved['clients_id'] === (int) $linked['client_id']
                        && strcasecmp((string) $saved['clients_name'], (string) ($linked['client_name'] ?? '')) === 0
                        && strcasecmp((string) $saved['keyperson'], (string) ($linked['keyperson'] ?? '')) === 0) {
                        unset($savedRows[$key]);
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) throw new RuntimeException('Saved tender relationships do not match the review.', 409);
            }
        }
    }
    if (!$record || $expected === '' || $name === '' || strcasecmp($expected, $name) !== 0) {
        throw new RuntimeException('The saved record does not match the AI draft. Check the register before creating anything again.', 409);
    }
    return ['label' => aiChatActionDefinitions()[$type]['label'], 'name' => $name, 'id' => $id, 'type' => $type, 'event' => 'created'];
}

/** One formatter shared by every vetted AI action type. Result is persisted, not generated by the AI provider. */
function aiChatActionSuccessText(array $verified): string
{
    $name = trim(preg_replace('/\s+/u', ' ', (string) $verified['name']) ?? (string) $verified['name']);
    $event = (string) ($verified['event'] ?? 'created');
    $verbs = [
        'created' => 'created successfully',
        'updated' => 'updated successfully',
        'deleted' => 'deleted successfully',
        'status_changed' => 'updated to the approved status',
    ];
    if (!isset($verbs[$event])) throw new LogicException('Unsupported AI confirmation event.');
    $next = $event === 'deleted' ? 'The action was verified and recorded.' : 'Open the saved record using the action card above.';
    return sprintf('✅ %s “%s” was %s in Dynabase (ID: %d). %s',
        $verified['label'], $name, $verbs[$event], (int) $verified['id'], $next);
}
