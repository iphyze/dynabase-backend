<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../includes/request.php';
require_once __DIR__ . '/../../../../includes/surveys.php';
require_once __DIR__ . '/../../../../includes/audit.php';

requireMethod('POST');
$authUser = authenticateUser();
$payload = readJsonBody();
$id = requiredIntFromPayload($payload, 'id', 'Survey invitation ID');
$invitation = dbFetchOne($conn, invitationSelectSql() . ' WHERE i.id = ? LIMIT 1', 'i', [$id]);
if (!$invitation) throw new RuntimeException('Survey link not found.', 404);
if (surveyInvitationStatus($invitation) === 'revoked') throw new RuntimeException('This survey link is already revoked.', 409);

$stmt = dbExecute($conn, "UPDATE client_survey_invitations SET status = 'revoked', revoked_by = ?, revoked_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?", 'ii', [(int) $authUser['id'], $id]);
$stmt->close();
writeAuditLog($conn, $authUser, 'client_surveys.invitation_revoked', 'client_survey_invitation', $id);
jsonResponse(['status' => 'Success', 'message' => 'Client survey link revoked successfully.']);
