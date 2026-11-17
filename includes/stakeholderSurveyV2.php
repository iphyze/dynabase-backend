<?php
declare(strict_types=1);

/**
 * Version 2 of Lambert's Stakeholder Survey (November 2026 PDF).
 * Version 1 responses and the existing public form remain supported.
 * Only Full Name and Company / Organisation are explicitly marked required
 * in the reference document. "Others" descriptions are conditionally required.
 */
const STAKEHOLDER_SURVEY_V2_RATINGS = [
    'A' => ['label' => 'Excellent', 'score' => 100],
    'B' => ['label' => 'Good', 'score' => 75],
    'C' => ['label' => 'Average', 'score' => 50],
    'D' => ['label' => 'Poor', 'score' => 25],
    'N/A' => ['label' => 'Not Applicable', 'score' => null],
];
const STAKEHOLDER_SURVEY_V2_PERFORMANCE = [
    'communication' => [
        'title' => 'Communication',
        'question' => 'How would you rate the clarity, consistency and effectiveness of communication from Lambert throughout your engagement?',
    ],
    'responsiveness' => [
        'title' => 'Responsiveness',
        'question' => "How would you rate Lambert's timeliness in responding to enquiries, requests, instructions and project-related correspondence?",
    ],
    'technical_competence' => [
        'title' => 'Technical Competence & Expertise',
        'question' => "How would you assess Lambert's technical knowledge, engineering expertise and ability to provide appropriate solutions to project requirements?",
    ],
    'quality_of_work' => [
        'title' => 'Quality of Work / Services',
        'question' => 'How satisfied are you with the quality, reliability and standard of work or services delivered by Lambert?',
    ],
    'documentation' => [
        'title' => 'Documentation & Reporting',
        'question' => 'How would you rate the accuracy, completeness and timeliness of technical submissions, reports, drawings and other relevant project documentation?',
    ],
    'professionalism' => [
        'title' => 'Professionalism & Conduct',
        'question' => "How would you assess the professionalism, integrity, cooperation and conduct of Lambert's personnel during your engagement?",
    ],
    'project_delivery' => [
        'title' => 'Project Delivery & Timeliness',
        'question' => 'How effectively does Lambert meet agreed project timelines, milestones, deliverables and other contractual or service commitments?',
    ],
    'coordination' => [
        'title' => 'Coordination & Collaboration',
        'question' => 'How effectively do Lambert coordinate and collaborate with clients, consultants, project managers, contractors and other stakeholders?',
    ],
    'problem_resolution' => [
        'title' => 'Problem Resolution',
        'question' => "How would you rate Lambert's ability to identify, address and resolve technical challenges, project issues and other concerns?",
    ],
    'complaints' => [
        'title' => 'Response to Complaints & Feedback',
        'question' => 'How effectively does Lambert address complaints, acknowledge feedback and implement corrective actions where necessary?',
    ],
    'health_safety' => [
        'title' => 'Health, Safety & Site Management',
        'question' => "How would you rate Lambert's compliance with safety requirements, site procedures, housekeeping and responsible work practices?",
    ],
    'working_relationship' => [
        'title' => 'Overall Working Relationship',
        'question' => 'How would you rate your overall experience and professional working relationship with Lambert?',
    ],
];
const STAKEHOLDER_SURVEY_V2_CATEGORIES = [
    'Client / Project Owner' => [
        'understanding_requirements' => ['title' => 'Understanding of Requirements', 'question' => 'How effectively did Lambert understand your project objectives, requirements and expectations?'],
        'delivery_expectations' => ['title' => 'Delivery Against Expectations', 'question' => "How satisfied are you with Lambert's ability to deliver against agreed project objectives and commitments?"],
    ],
    'Consultant (Architect / Engineer / Quantity Surveyor)' => [
        'technical_compliance' => ['title' => 'Technical Compliance', 'question' => 'How effectively did Lambert adhere to approved designs, specifications and technical requirements?'],
        'technical_collaboration' => ['title' => 'Technical Collaboration', 'question' => 'How effectively did Lambert coordinate technical matters and respond to design clarifications or instructions?'],
    ],
    'Project Manager / Project Management Consultant' => [
        'programme_coordination' => ['title' => 'Programme Coordination', 'question' => 'How effectively did Lambert align its activities with the overall project schedule and milestones?'],
        'progress_reporting' => ['title' => 'Progress Reporting', 'question' => 'How effectively did Lambert communicate progress, potential delays and project risks?'],
    ],
    'Main Contractor / Construction Partner' => [
        'site_coordination' => ['title' => 'Site Coordination', 'question' => 'How effectively did Lambert coordinate its activities with other contractors and site teams?'],
        'cooperation_flexibility' => ['title' => 'Cooperation & Flexibility', 'question' => 'How effectively did Lambert accommodate site requirements, work interfaces and changes?'],
    ],
    'Facility Manager / Building Operator' => [
        'operational_reliability' => ['title' => 'Operational Reliability', 'question' => "How satisfied are you with the reliability and effectiveness of Lambert's systems or maintenance services?"],
        'maintenance_support' => ['title' => 'Maintenance Support', 'question' => 'How effectively did Lambert respond to maintenance requirements, operational concerns or technical support requests?'],
    ],
    'Others' => [
        'collaboration' => ['title' => 'Collaboration', 'question' => 'How effectively did Lambert work and coordinate with your organisation?'],
        'commitments' => ['title' => 'Fulfilment of Commitments', 'question' => 'How effectively did Lambert meet its responsibilities within your engagement?'],
    ],
];
const STAKEHOLDER_SURVEY_V2_ENGAGEMENTS = [
    'Ongoing Project', 'Completed Project', 'Maintenance / Facility Management',
    'Technical / Professional Collaboration',
];
const STAKEHOLDER_SURVEY_V2_LOCATIONS = ['Nigeria', 'Ghana', 'Cote D’Iviore', 'Others'];
const STAKEHOLDER_SURVEY_V2_SERVICES = [
    'Electrical Services', 'Mechanical Services', 'Power Generation/ Solutions',
    'Renewable Energy', 'Energy Audit & Management', 'Facility Management / Maintenance',
    'Fit-Out / Retrofit Works', 'Others',
];
const STAKEHOLDER_SURVEY_V2_SERVICE_QUESTIONS = [
    'service_quality' => [
        'title' => 'Overall Service Quality',
        'question' => 'How would you rate the overall quality of service delivered in your selected service area(s)?',
    ],
    'service_reliability' => [
        'title' => 'Reliability of Technical Solutions',
        'question' => 'How would you rate the reliability and effectiveness of the technical solutions or services provided?',
    ],
];
const STAKEHOLDER_SURVEY_V2_SATISFACTION = [
    'Very Satisfied', 'Satisfied', 'Neutral', 'Dissatisfied', 'Very Dissatisfied',
];
const STAKEHOLDER_SURVEY_V2_FUTURE_ENGAGEMENT = ['Yes', 'Maybe', 'No'];
const STAKEHOLDER_SURVEY_V2_IMPROVEMENTS = [
    'Communication', 'Responsiveness', 'Technical competence', 'Quality of work/services',
    'Documentation & Reporting', 'Project Delivery / Timeliness', 'Issue Resolution',
    'Stakeholder Coordination', 'No major improvement required', 'Others',
];

function stakeholderSurveyV2Definition(): array
{
    return [
        'version' => 2,
        'required_fields' => ['filled_by', 'company'],
        'stakeholder_categories' => STAKEHOLDER_SURVEY_V2_CATEGORIES,
        'project_locations' => STAKEHOLDER_SURVEY_V2_LOCATIONS,
        'engagement_types' => STAKEHOLDER_SURVEY_V2_ENGAGEMENTS,
        'ratings' => STAKEHOLDER_SURVEY_V2_RATINGS,
        'performance_questions' => STAKEHOLDER_SURVEY_V2_PERFORMANCE,
        'service_areas' => STAKEHOLDER_SURVEY_V2_SERVICES,
        'service_questions' => STAKEHOLDER_SURVEY_V2_SERVICE_QUESTIONS,
        'satisfaction_options' => STAKEHOLDER_SURVEY_V2_SATISFACTION,
        'future_engagement_options' => STAKEHOLDER_SURVEY_V2_FUTURE_ENGAGEMENT,
        'recommendation_ratings' => ['A' => 'Very Likely', 'B' => 'Likely', 'C' => 'Unlikely', 'D' => 'Very Unlikely'],
        'improvement_options' => STAKEHOLDER_SURVEY_V2_IMPROVEMENTS,
    ];
}

function surveyV2OptionalChoice(array $payload, string $field, array $allowed, string $label): string
{
    $value = optionalStringField($payload, $field, 150);
    if ($value !== '' && !in_array($value, $allowed, true)) {
        throw new RuntimeException("Please choose a valid {$label}.", 422);
    }
    return $value;
}

function surveyV2RatingGroup(array $payload, string $field, array $questions): array
{
    $submitted = $payload[$field] ?? [];
    if (!is_array($submitted) || array_is_list($submitted) && $submitted !== []) {
        throw new RuntimeException('Invalid survey ratings.', 422);
    }
    $clean = [];
    foreach ($questions as $key => $question) {
        $rating = $submitted[$key] ?? '';
        if (!is_string($rating) || !in_array($rating, ['', 'A', 'B', 'C', 'D', 'N/A'], true)) {
            throw new RuntimeException('Invalid rating for ' . $question['title'] . '.', 422);
        }
        $clean[$key] = $rating;
    }
    foreach ($submitted as $key => $value) {
        if (!array_key_exists((string) $key, $questions)) {
            throw new RuntimeException('Unrecognized survey rating.', 422);
        }
    }
    return $clean;
}

function surveyV2MultiSelect(array $payload, string $field, array $allowed, string $label): array
{
    $values = $payload[$field] ?? [];
    if (!is_array($values) || count($values) > count($allowed) || !array_is_list($values)) {
        throw new RuntimeException("Please select valid {$label}.", 422);
    }
    $clean = [];
    foreach ($values as $value) {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new RuntimeException("Please select valid {$label}.", 422);
        }
        $clean[$value] = $value;
    }
    return array_values($clean);
}

function surveyV2OtherDetail(array $payload, string $field, bool $isSelected, string $label): string
{
    if (!$isSelected) return '';
    return requireStringField($payload, $field, $label, 500);
}

/** No database calls: validate and normalize a version 2 submission. */
function normalizeStakeholderSurveyV2(array $payload): array
{
    if (cleanString($payload['website'] ?? '') !== '' || cleanString($payload['company_url'] ?? '') !== '') {
        throw new RuntimeException('Unable to accept this submission.', 422);
    }

    $name = requireStringField($payload, 'filled_by', 'Full name', 255);
    $company = requireStringField($payload, 'company', 'Company / Organisation', 255);
    $email = optionalEmailField($payload, 'email', 'Email address');
    $category = surveyV2OptionalChoice($payload, 'stakeholder_category', array_keys(STAKEHOLDER_SURVEY_V2_CATEGORIES), 'stakeholder category');
    $categoryOther = surveyV2OtherDetail($payload, 'stakeholder_other', $category === 'Others', 'Other stakeholder category');
    $location = surveyV2OptionalChoice($payload, 'location', STAKEHOLDER_SURVEY_V2_LOCATIONS, 'project location');
    $locationOther = surveyV2OtherDetail($payload, 'project_location_other', $location === 'Others', 'Other project location');
    $engagement = surveyV2OptionalChoice($payload, 'nature_of_engagement', STAKEHOLDER_SURVEY_V2_ENGAGEMENTS, 'nature of engagement');

    $performance = surveyV2RatingGroup($payload, 'performance_ratings', STAKEHOLDER_SURVEY_V2_PERFORMANCE);
    // Discard any ratings from a previously selected category after the user changes it.
    $selectedQuestions = $category === '' ? [] : STAKEHOLDER_SURVEY_V2_CATEGORIES[$category];
    $stakeholderProvided = $payload['stakeholder_ratings'] ?? [];
    if (!is_array($stakeholderProvided)) {
        throw new RuntimeException('Invalid stakeholder ratings.', 422);
    }
    $stakeholderInput = array_intersect_key($stakeholderProvided, $selectedQuestions);
    $stakeholder = surveyV2RatingGroup(['stakeholder_ratings' => $stakeholderInput], 'stakeholder_ratings', $selectedQuestions);
    $services = surveyV2MultiSelect($payload, 'service_areas', STAKEHOLDER_SURVEY_V2_SERVICES, 'service areas');
    $serviceOther = surveyV2OtherDetail($payload, 'service_area_other', in_array('Others', $services, true), 'Other service area');
    $serviceRatings = surveyV2RatingGroup($payload, 'service_ratings', STAKEHOLDER_SURVEY_V2_SERVICE_QUESTIONS);

    $satisfaction = surveyV2OptionalChoice($payload, 'relationship_satisfaction', STAKEHOLDER_SURVEY_V2_SATISFACTION, 'relationship satisfaction');
    $future = surveyV2OptionalChoice($payload, 'future_engagement', STAKEHOLDER_SURVEY_V2_FUTURE_ENGAGEMENT, 'future engagement');
    $recommendation = surveyV2OptionalChoice($payload, 'recommendation', ['A', 'B', 'C', 'D'], 'recommendation rating');
    $improvements = surveyV2MultiSelect($payload, 'improvement_priorities', STAKEHOLDER_SURVEY_V2_IMPROVEMENTS, 'improvement priorities');
    if (in_array('No major improvement required', $improvements, true) && count($improvements) > 1) {
        throw new RuntimeException('No major improvement required cannot be combined with other improvement priorities.', 422);
    }
    $improvementOther = surveyV2OtherDetail($payload, 'improvement_other', in_array('Others', $improvements, true), 'Other improvement priority');

    $ratedScores = [];
    foreach (array_merge(array_values($performance), array_values($stakeholder), array_values($serviceRatings)) as $rating) {
        if ($rating !== '' && $rating !== 'N/A') {
            $ratedScores[] = STAKEHOLDER_SURVEY_V2_RATINGS[$rating]['score'];
        }
    }
    $ratedCount = count($ratedScores);
    $overallScore = $ratedCount > 0 ? round(array_sum($ratedScores) / $ratedCount, 2) : 0.0;

    $position = optionalStringField($payload, 'position', 255);
    $projectTitle = optionalStringField($payload, 'project_title', 255);
    $officeAddress = optionalStringField($payload, 'office_address', 1000);
    $phone = optionalStringField($payload, 'phone_number', 80);

    $answers = [
        'respondent' => [
            'filled_by' => $name,
            'email' => $email,
            'position' => $position,
            'company' => $company,
            'stakeholder_category' => $category,
            'stakeholder_other' => $categoryOther,
            'project_title' => $projectTitle,
            'location' => $location,
            'project_location_other' => $locationOther,
            'nature_of_engagement' => $engagement,
            'office_address' => $officeAddress,
            'phone_number' => $phone,
        ],
        'performance_ratings' => $performance,
        'stakeholder_ratings' => $stakeholder,
        'service_areas' => $services,
        'service_area_other' => $serviceOther,
        'service_ratings' => $serviceRatings,
        'relationship_satisfaction' => $satisfaction,
        'future_engagement' => $future,
        'recommendation' => $recommendation,
        'improvement_priorities' => $improvements,
        'improvement_other' => $improvementOther,
        'rated_count' => $ratedCount,
    ];
    $json = json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    return [
        'survey_version' => 2,
        'filled_by' => $name,
        'company' => $company,
        'email' => $email,
        'position' => $position,
        'project_title' => $projectTitle,
        'location' => $location === 'Others' ? $locationOther : $location,
        'office_address' => $officeAddress,
        'phone_number' => $phone,
        'stakeholder_category' => $category,
        'nature_of_engagement' => $engagement,
        'survey_answers' => $json,
        'survey_rated_count' => $ratedCount,
        'overall_score' => $overallScore,
    ];
}
