<?php
declare(strict_types=1);

/** Pure audit eligibility and backfill planning. No database calls. */
function giftActivityReconcileEligible(array $entry): bool
{
    $role = (string) ($entry['actor_role'] ?? '');
    $actor = (int) ($entry['actor_user_id'] ?? 0);
    $owner = (int) ($entry['owner_pms_admin_id'] ?? 0);
    $year = (int) ($entry['gift_year'] ?? 0);
    $listId = (int) ($entry['gift_list_id'] ?? 0);
    if ($actor <= 0 || $owner <= 0 || $year < 2000 || $listId <= 0) {
        return false;
    }

    $allowed = [
        'gift_list.created', 'gift_list.updated', 'gift_list.bulk_updated',
        'gift_list.keypersons_added', 'gift_list.portal_saved',
        'gift_list.item_updated',
    ];
    if (!in_array((string) ($entry['action'] ?? ''), $allowed, true)) {
        return false;
    }
    // Item updates are audited against a key person, not a list ID. The SQL
    // lookup must confirm that the item belongs to this precise owner/year.
    if (($entry['action'] ?? '') === 'gift_list.item_updated'
        && (int) ($entry['verified_gift_item_link'] ?? 0) !== 1) {
        return false;
    }
    if (($entry['action'] ?? '') === 'gift_list.keypersons_added'
        && (int) ($entry['added_count'] ?? -1) === 0) {
        return false;
    }

    $meta = json_decode((string) ($entry['metadata'] ?? ''), true);
    if (!is_array($meta)
        || (int) ($meta['gift_year'] ?? 0) !== $year
        || (int) ($meta['owner_pms_admin_id'] ?? 0) !== $owner) {
        return false;
    }

    if ($role === 'pms_admin') {
        return $actor === $owner && ($entry['current_role'] ?? '') === 'pms_admin';
    }
    if ($role === 'admin') {
        return $actor === $owner && ($entry['current_role'] ?? '') === 'admin'
            && (int) ($entry['current_is_pms_admin'] ?? 0) === 1;
    }
    if ($role === 'pms_user') {
        return ($entry['current_role'] ?? '') === 'pms_user'
            && (int) ($entry['current_parent_pms_admin_id'] ?? 0) === $owner;
    }
    return false;
}


function giftActivityReconcilePlan(array $auditRows, array $existingKeys): array
{
    $plan = [];
    $excluded = 0;
    $already = 0;
    foreach ($auditRows as $row) {
        if (!giftActivityReconcileEligible($row)) {
            $excluded++;
            continue;
        }
        $key = (int) $row['owner_pms_admin_id'] . ':' . (int) $row['gift_year'];
        if (isset($existingKeys[$key])) {
            $already++;
            continue;
        }
        // Ordered earliest audit wins, so first_started_at is historical.
        if (!isset($plan[$key])) {
            $plan[$key] = $row;
        }
    }
    return ['candidates' => array_values($plan), 'excluded' => $excluded, 'already' => $already];
}

