<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/xlsxReport.php';
require_once __DIR__ . '/../../../includes/request.php';
require_once __DIR__ . '/../../../includes/surveys.php';

requireMethod('GET');
$authUser = authenticateUser();

$q = cleanString($_GET['q'] ?? $_GET['search'] ?? '');
$rating = cleanString($_GET['rating'] ?? '');
$status = cleanString($_GET['status'] ?? '');
$clientId = (int) ($_GET['client_id'] ?? 0);
$dateFrom = cleanString($_GET['date_from'] ?? '');
$dateTo = cleanString($_GET['date_to'] ?? '');
$where = ' WHERE s.deleted_at IS NULL';
$types = '';
$params = [];
if ($q !== '') {
    $where .= ' AND (s.company LIKE ? OR s.project_title LIKE ? OR s.filled_by LIKE ? OR s.email LIKE ? OR s.submission_reference LIKE ? OR s.location LIKE ?)';
    $like = '%' . $q . '%';
    $types .= 'ssssss';
    $params = array_merge($params, array_fill(0, 6, $like));
}
if ($rating !== '') {
    $ranges = ['Excellent' => [87.5,100], 'Good' => [62.5,87.49], 'Average' => [37.5,62.49], 'Poor' => [0,37.49]];
    if (isset($ranges[$rating])) { $where .= ' AND (s.survey_version = 1 OR s.survey_rated_count > 0) AND s.overall_score BETWEEN ? AND ?'; $types .= 'dd'; $params[] = $ranges[$rating][0]; $params[] = $ranges[$rating][1]; }
}
if ($status !== '') { $where .= ' AND s.response_status = ?'; $types .= 's'; $params[] = $status; }
if ($clientId > 0) { $where .= ' AND s.client_id = ?'; $types .= 'i'; $params[] = $clientId; }
if ($dateFrom !== '') { $where .= ' AND DATE(s.createdAt) >= ?'; $types .= 's'; $params[] = $dateFrom; }
if ($dateTo !== '') { $where .= ' AND DATE(s.createdAt) <= ?'; $types .= 's'; $params[] = $dateTo; }

$rows = dbFetchAll($conn, surveySelectSql() . $where . ' ORDER BY s.createdAt DESC LIMIT 10000', $types, $params);
$filename = 'dynabase-client-surveys-' . date('Y-m-d') . '.xlsx';
// Retain every legacy column in its original order. Append v2-specific data so
// historical spreadsheets remain compatible and v2 answers are never discarded.
$exportRows = [];
$exportHeaders = [
    'Reference','Company','Project','Respondent','Position','Email','Phone','Location',
    'Overall Score','Quality','Timeline','Expertise','Communication','Resolution',
    'Cleanliness','Safety','Complaint Response','Electrical Services','Mechanical Services',
    'Status','Submitted At',
    'Survey Version','Stakeholder Category','Nature of Engagement','Rated Criteria',
    'Other Stakeholder Category','Other Project Location','Office Address',
];
$performanceQuestions = STAKEHOLDER_SURVEY_V2_PERFORMANCE;
foreach ($performanceQuestions as $item) $exportHeaders[] = 'Performance: ' . $item['title'];
foreach (STAKEHOLDER_SURVEY_V2_CATEGORIES as $category => $questions) {
    foreach ($questions as $item) $exportHeaders[] = 'Stakeholder (' . $category . '): ' . $item['title'];
}
$exportHeaders[] = 'Service Areas';
$exportHeaders[] = 'Other Service Area';
foreach (STAKEHOLDER_SURVEY_V2_SERVICE_QUESTIONS as $item) $exportHeaders[] = 'Service: ' . $item['title'];
array_push($exportHeaders, 'Relationship Satisfaction', 'Future Engagement', 'Recommendation',
    'Improvement Priorities', 'Other Improvement Priority');

foreach ($rows as $row) {
    $version = (int) ($row['survey_version'] ?? 1);
    $answers = $version === 2 ? json_decode((string) ($row['survey_answers'] ?? ''), true) : [];
    if (!is_array($answers)) $answers = [];
    $respondent = $answers['respondent'] ?? [];
    $output = [
        $row['submission_reference'],$row['company'],$row['project_title'],$row['filled_by'],
        $row['position'],$row['email'],$row['phone_number'],$row['location'],
        $version === 2 && (int) $row['survey_rated_count'] === 0 ? 'Not rated' : $row['overall_score'],
        $row['quality'],$row['timeline'],$row['expertise'],$row['communication'],$row['resolution'],
        $row['cleaniness'],$row['safety'],$row['response'],$row['electrical_services'],
        $row['mechanical_services'],$row['response_status'],$row['createdAt'],
        $version, $row['stakeholder_category'] ?? '', $row['nature_of_engagement'] ?? '',
        $version === 2 ? $row['survey_rated_count'] : 8,
        $respondent['stakeholder_other'] ?? '', $respondent['project_location_other'] ?? '',
        $row['office_address'] ?? '',
    ];
    foreach (array_keys($performanceQuestions) as $key) {
        $output[] = $answers['performance_ratings'][$key] ?? '';
    }
    foreach (STAKEHOLDER_SURVEY_V2_CATEGORIES as $questions) {
        foreach (array_keys($questions) as $key) {
            $output[] = $answers['stakeholder_ratings'][$key] ?? '';
        }
    }
    $output[] = implode(', ', $answers['service_areas'] ?? []);
    $output[] = $answers['service_area_other'] ?? '';
    foreach (array_keys(STAKEHOLDER_SURVEY_V2_SERVICE_QUESTIONS) as $key) {
        $output[] = $answers['service_ratings'][$key] ?? '';
    }
    $output[] = $answers['relationship_satisfaction'] ?? '';
    $output[] = $answers['future_engagement'] ?? '';
    $output[] = $answers['recommendation'] ?? '';
    $output[] = implode(', ', $answers['improvement_priorities'] ?? []);
    $output[] = $answers['improvement_other'] ?? '';
    $exportRows[] = $output;
}
dynabaseOutputTableXlsx($filename, 'Client Surveys', $exportHeaders, $exportRows);
