<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/request.php';
require_once __DIR__ . '/../../includes/authorization.php';

requireMethod('GET');
authenticateUser();

jsonResponse([
    'status' => 'Success',
    'message' => 'Project options retrieved successfully.',
    'data' => [
        'divisions' => [
            ['value' => 'Building & Factories', 'label' => 'Building & Factories'],
            ['value' => 'Oil & Gas', 'label' => 'Oil & Gas'],
            ['value' => 'Power', 'label' => 'Power'],
            ['value' => 'Data Centre', 'label' => 'Data Centre'],
            ['value' => 'Facilities & Maintenance', 'label' => 'Facilities & Maintenance'],
        ],
        'project_statuses' => [
            ['value' => 'On Hold', 'label' => 'On Hold'],
            ['value' => 'Approved', 'label' => 'Approved'],
            ['value' => 'Declined', 'label' => 'Declined'],
            ['value' => 'Abortive', 'label' => 'Abortive'],
        ],
        'progress_statuses' => [
            ['value' => 'Pending', 'label' => 'Received'],
            ['value' => 'In Progress', 'label' => 'In Progress'],
            ['value' => 'Submitted', 'label' => 'Submitted'],
            ['value' => 'Awaiting', 'label' => 'Feedbacks'],
            ['value' => 'Declined', 'label' => 'Declined'],
            ['value' => 'Awarded', 'label' => 'Awarded'],
        ],
        'currencies' => [
            ['value' => 'NGN', 'label' => 'Naira'],
            ['value' => 'USD', 'label' => 'Dollars'],
            ['value' => 'GBP', 'label' => 'Pounds'],
            ['value' => 'EUR', 'label' => 'Euros'],
            ['value' => 'ZAR', 'label' => 'Rands'],
            ['value' => 'GHC', 'label' => 'Cedis'],
            ['value' => 'CFA', 'label' => 'Cefas'],
        ],
        'importance_levels' => [
            ['value' => 'Very High', 'label' => 'Very High'],
            ['value' => 'High', 'label' => 'High'],
            ['value' => 'Medium', 'label' => 'Medium'],
            ['value' => 'Low', 'label' => 'Low'],
        ],
        'contract_types' => [
            ['value' => 'Lump Sum', 'label' => 'Lump Sum'],
            ['value' => 'Remeasured', 'label' => 'Remeasured'],
            ['value' => 'Design & Build', 'label' => 'Design & Build'],
            ['value' => 'Preliminary Budgeting', 'label' => 'Preliminary Budgeting'],
            ['value' => 'Design Evaluation', 'label' => 'Design Evaluation'],
        ],
        'preliminary_pricing' => [
            ['value' => 'Standard (Combined)', 'label' => 'Standard (Combined)'],
            ['value' => 'Per Package', 'label' => 'Per Package'],
        ],
        'pricing_strategies' => [
            ['value' => 'As Per Specs', 'label' => 'As Per Specs'],
            ['value' => 'Alternative On Brands are Acceptable', 'label' => 'Alternative On Brands are Acceptable'],
            ['value' => 'Directly include VEs Related To Brands & Specifications & Clarify', 'label' => 'Directly include VEs Related To Brands & Specifications & Clarify'],
            ['value' => 'Directly include VEs Related To Design/Sizing & Clarify', 'label' => 'Directly include VEs Related To Design/Sizing & Clarify'],
            ['value' => 'Include VEs without Mentioning it in the Clarifications', 'label' => 'Include VEs without Mentioning it in the Clarifications'],
            ['value' => 'As Per Specs: VEs/Alternative Brands to be submitted separately', 'label' => 'As Per Specs: VEs/Alternative Brands to be submitted separately'],
        ],
        'vendor_information' => [
            ['value' => 'Only use Project Name', 'label' => 'Only use Project Name'],
            ['value' => 'Project Name & Tender Code', 'label' => 'Project Name & Tender Code'],
        ],
        'naira_rates' => [
            ['value' => 'Equipment/Imported Items', 'label' => 'Equipment/Imported Items'],
            ['value' => 'First Fix', 'label' => 'First Fix'],
        ],
        'procurement_types' => [
            ['value' => 'Standard (Sea Freight)', 'label' => 'Standard (Sea Freight)'],
            ['value' => 'Fast Delivery (Local Market if possible + Air Freight)', 'label' => 'Fast Delivery (Local Market if possible + Air Freight)'],
        ],
        'yes_no' => [
            ['value' => 'Yes', 'label' => 'Yes'],
            ['value' => 'No', 'label' => 'No'],
        ],
    ],
]);
