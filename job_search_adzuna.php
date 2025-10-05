<?php
// Adzuna API configuration
$app_id = "4c09416e";
$app_key = "0655e36c01942f485da7b521623f4608";
$country = $_GET['country'] ?? 'us';

// Search parameters with validation
$what = isset($_GET['what']) ? trim($_GET['what']) : 'software engineer';
$where = isset($_GET['where']) ? trim($_GET['where']) : 'new york';
$page = max(1, intval($_GET['page'] ?? 1));
$results_per_page = 15;

// Build API URL with error handling
$url = "https://api.adzuna.com/v1/api/jobs/$country/search/$page?" . http_build_query([
    'app_id' => $app_id,
    'app_key' => $app_key,
    'results_per_page' => $results_per_page,
    'what' => $what,
    'where' => $where,
    'sort_by' => 'relevance'
]);

// Fetch data with improved error handling
$context = stream_context_create([
    'http' => [
        'timeout' => 10,
        'user_agent' => 'JobSearch/1.0'
    ]
]);

$response = @file_get_contents($url, false, $context);
$data = $response ? json_decode($response, true) : null;
$error_message = '';

if (!$data) {
    $error_message = 'Unable to fetch job data. Please try again later.';
} elseif (isset($data['exception'])) {
    $error_message = 'API Error: ' . htmlspecialchars($data['exception']);
}

// Helper function to format salary
function formatSalary($min, $max) {
    if ($min && $max) {
        return '$' . number_format($min) . ' - $' . number_format($max);
    } elseif ($min) {
        return 'From $' . number_format($min);
    } elseif ($max) {
        return 'Up to $' . number_format($max);
    }
    return 'Salary not specified';
}

// Helper function to format job description
function formatDescription($description, $length = 250) {
    $clean = strip_tags($description);
    return strlen($clean) > $length ? substr($clean, 0, $length) . '...' : $clean;
}

// Helper function to get time ago
function timeAgo($dateString) {
    $time = time() - strtotime($dateString);
    
    if ($time < 60) return 'Just now';
    if ($time < 3600) return floor($time/60) . ' minutes ago';
    if ($time < 86400) return floor($time/3600) . ' hours ago';
    if ($time < 2592000) return floor($time/86400) . ' days ago';
    
    return date('M j, Y', strtotime($dateString));
}

$total_results = $data['count'] ?? 0;
$total_pages = ceil($total_results / $results_per_page);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Job Search – AimAI</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #7c3aed 0%, #3b82f6 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            font-weight: 700;
        }

        .header p {
            opacity: 0.9;
            font-size: 1.1rem;
        }

        .search-section {
            padding: 30px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }

        .search-form {
            display: grid;
            grid-template-columns: 1fr 1fr auto auto;
            gap: 15px;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 5px;
            font-weight: 600;
            color: #374151;
        }

        .form-group input, .form-group select {
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #7c3aed;
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
        }

        .search-btn {
            padding: 12px 24px;
            background: linear-gradient(135deg, #7c3aed 0%, #3b82f6 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease;
        }

        .search-btn:hover {
            transform: translateY(-2px);
        }

        .results-header {
            padding: 20px 30px;
            background: white;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .results-count {
            font-size: 1.1rem;
            color: #6b7280;
        }

        .results-section {
            padding: 20px 30px;
            background: #f8fafc;
        }

        .job-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            border-left: 4px solid #7c3aed;
            transition: all 0.3s ease;
        }

        .job-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }

        .job-card h3 {
            color: #1f2937;
            font-size: 1.4rem;
            margin-bottom: 12px;
            font-weight: 600;
        }

        .job-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            margin-bottom: 15px;
        }

        .meta-item {
            display: flex;
            align-items: center;
            color: #6b7280;
            font-size: 0.9rem;
        }

        .meta-item strong {
            color: #374151;
            margin-right: 8px;
        }

        .job-description {
            color: #4b5563;
            line-height: 1.6;
            margin-bottom: 15px;
        }

        .job-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .apply-btn {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 10px 20px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .apply-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .job-date {
            color: #9ca3af;
            font-size: 0.85rem;
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 30px;
            padding: 20px;
        }

        .pagination a, .pagination span {
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            color: #6b7280;
            background: white;
            border: 1px solid #e2e8f0;
        }

        .pagination a:hover {
            background: #f3f4f6;
        }

        .pagination .current {
            background: #7c3aed;
            color: white;
            border-color: #7c3aed;
        }

        .error-message {
            background: #fef2f2;
            color: #dc2626;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid #dc2626;
        }

        .no-results {
            text-align: center;
            padding: 40px;
            color: #6b7280;
        }

        .no-results h3 {
            color: #374151;
            margin-bottom: 10px;
        }

        @media (max-width: 768px) {
            .search-form {
                grid-template-columns: 1fr;
            }
            
            .job-meta {
                grid-template-columns: 1fr;
            }
            
            .results-header {
                flex-direction: column;
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔍 Smart Job Search</h1>
            <p>Powered by Adzuna API - Find your dream job today</p>
        </div>

        <div class="search-section">
            <form method="get" class="search-form">
                <div class="form-group">
                    <label for="what">Job Title</label>
                    <input type="text" id="what" name="what" 
                           placeholder="e.g. Software Engineer" 
                           value="<?= htmlspecialchars($what) ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="where">Location</label>
                    <input type="text" id="where" name="where" 
                           placeholder="e.g. New York" 
                           value="<?= htmlspecialchars($where) ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="country">Country</label>
                    <select id="country" name="country">
                        <optgroup label="North America">
                            <option value="us" <?= $country === 'us' ? 'selected' : '' ?>>🇺🇸 United States</option>
                            <option value="ca" <?= $country === 'ca' ? 'selected' : '' ?>>🇨🇦 Canada</option>
                        </optgroup>
                        <optgroup label="Europe">
                            <option value="gb" <?= $country === 'gb' ? 'selected' : '' ?>>🇬🇧 United Kingdom</option>
                            <option value="de" <?= $country === 'de' ? 'selected' : '' ?>>🇩🇪 Germany</option>
                            <option value="fr" <?= $country === 'fr' ? 'selected' : '' ?>>🇫🇷 France</option>
                            <option value="it" <?= $country === 'it' ? 'selected' : '' ?>>🇮🇹 Italy</option>
                            <option value="nl" <?= $country === 'nl' ? 'selected' : '' ?>>🇳🇱 Netherlands</option>
                            <option value="es" <?= $country === 'es' ? 'selected' : '' ?>>🇪🇸 Spain</option>
                            <option value="ch" <?= $country === 'ch' ? 'selected' : '' ?>>🇨🇭 Switzerland</option>
                            <option value="be" <?= $country === 'be' ? 'selected' : '' ?>>🇧🇪 Belgium</option>
                            <option value="at" <?= $country === 'at' ? 'selected' : '' ?>>🇦🇹 Austria</option>
                            <option value="pl" <?= $country === 'pl' ? 'selected' : '' ?>>🇵🇱 Poland</option>
                        </optgroup>
                        <optgroup label="Asia Pacific">
                            <option value="au" <?= $country === 'au' ? 'selected' : '' ?>>🇦🇺 Australia</option>
                            <option value="nz" <?= $country === 'nz' ? 'selected' : '' ?>>🇳🇿 New Zealand</option>
                            <option value="sg" <?= $country === 'sg' ? 'selected' : '' ?>>🇸🇬 Singapore</option>
                            <option value="in" <?= $country === 'in' ? 'selected' : '' ?>>🇮🇳 India</option>
                        </optgroup>
                        <optgroup label="Other">
                            <option value="za" <?= $country === 'za' ? 'selected' : '' ?>>🇿🇦 South Africa</option>
                            <option value="br" <?= $country === 'br' ? 'selected' : '' ?>>🇧🇷 Brazil</option>
                        </optgroup>
                    </select>
                </div>
                
                <button type="submit" class="search-btn">Search Jobs</button>
            </form>
        </div>

        <?php if ($error_message): ?>
            <div class="error-message">
                <strong>Error:</strong> <?= $error_message ?>
            </div>
        <?php endif; ?>

        <?php if ($data && isset($data['results']) && !empty($data['results'])): ?>
            <div class="results-header">
                <div class="results-count">
                    <strong><?= number_format($total_results) ?></strong> jobs found for 
                    <strong><?= htmlspecialchars($what) ?></strong> in 
                    <strong><?= htmlspecialchars($where) ?></strong>
                </div>
                <div>Page <?= $page ?> of <?= $total_pages ?></div>
            </div>

            <div class="results-section">
                <?php foreach ($data['results'] as $job): ?>
                    <div class="job-card">
                        <h3><?= htmlspecialchars($job['title']) ?></h3>
                        
                        <div class="job-meta">
                            <div class="meta-item">
                                <strong>Company:</strong> 
                                <?= htmlspecialchars($job['company']['display_name']) ?>
                            </div>
                            <div class="meta-item">
                                <strong>Location:</strong> 
                                <?= htmlspecialchars($job['location']['display_name']) ?>
                            </div>
                            <div class="meta-item">
                                <strong>Salary:</strong> 
                                <?= formatSalary($job['salary_min'] ?? null, $job['salary_max'] ?? null) ?>
                            </div>
                            <div class="meta-item">
                                <strong>Type:</strong> 
                                <?= htmlspecialchars($job['contract_type'] ?? 'Not specified') ?>
                            </div>
                        </div>

                        <div class="job-description">
                            <?= htmlspecialchars(formatDescription($job['description'])) ?>
                        </div>

                        <div class="job-actions">
                            <a href="<?= htmlspecialchars($job['redirect_url']) ?>" 
                               target="_blank" class="apply-btn">Apply Now</a>
                            <span class="job-date">
                                <?= timeAgo($job['created']) ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">Previous</a>
                        <?php endif; ?>

                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <?php if ($i == $page): ?>
                                <span class="current"><?= $i ?></span>
                            <?php else: ?>
                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="no-results">
                <h3>No jobs found</h3>
                <p>Try adjusting your search terms or location.</p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>