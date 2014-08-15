<?php

date_default_timezone_set('UTC');

function usort_categories($one, $two)
{
    $one_frequency = intval($one['frequency']);
    $two_frequency = intval($two['frequency']);
    if ($one_frequency != $two_frequency) {
        return ($one_frequency < $two_frequency) ? 1 : -1;
    }

    $one_title = $one['title'];
    $two_title = $two['title'];
    if ($one_title != $two_title) {
        return ($one_title < $two_title) ? -1 : 1;
    }

    return 0;
}

function usort_keywords_1($one, $two)
{
    $one = floatval($one['contents']['score'][0]);
    $two = floatval($two['contents']['score'][0]);
    if ($one == $two) {
        return 0;
    }

    return ($one > $two) ? -1 : 1;
}

function usort_keywords_2($one, $two)
{
    $one_position = intval($one['position']);
    $two_position = intval($two['position']);
    if ($one_position != $two_position) {
        return ($one_position < $two_position) ? -1 : 1;
    }

    $one_score = $one['contents']['score'][0];
    $two_score = $two['contents']['score'][0];
    if ($one_score != $two_score) {
        return ($one_score > $two_score) ? -1 : 1;
    }

    return 0;
}

function usort_logos($one, $two)
{
    $one = strtolower($one['file_name']);
    $two = strtolower($two['file_name']);
    if ($one == $two) {
        return 0;
    }

    return ($one < $two) ? -1 : 1;
}

function get_category($application, $category_id) {
    $categories = array();
    while (true) {
        $query = <<<EOD
SELECT `category_id`, `title`
FROM `tools_ce_categories`
WHERE `id` = ?
EOD;
        $row = $application['db']->fetchAssoc($query, array(
            $category_id,
        ));
        $categories[] = $row['title'];
        $category_id = $row['category_id'];
        if (!$category_id) {
            break;
        }
    }

    return implode(' > ', array_reverse($categories));
}

function get_categories($application, $category_id, $prefixes) {
    $categories = array();
    if (count($prefixes) >= 3) {
        return $categories;
    }
    if ($category_id) {
        $query = <<<EOD
SELECT *
FROM `tools_ce_categories`
WHERE `category_id` = ?
ORDER BY `title` ASC
EOD;
    } else {
        $query = <<<EOD
SELECT *
FROM `tools_ce_categories`
WHERE `category_id` IS NULL
ORDER BY `title` ASC
EOD;
    }
    $rows = $application['db']->fetchAll($query, array($category_id));
    if ($rows) {
        foreach ($rows as $row) {
            $categories[] = array(
                $row['id'],
                sprintf(
                    '%s%s',
                    implode(' > ', array_merge($prefixes, array(''))),
                    $row['title']
                ),
            );
            $categories = array_merge($categories, get_categories(
                $application, $row['id'],
                array_merge($prefixes, array($row['title']))
            ));
        }
    }

    return $categories;
}

function get_count_and_keywords($application, $user, $keywords) {
    $keywords = explode("\n", $keywords);
    if (!empty($keywords)) {
        foreach ($keywords as $key => $value) {
            $value = trim($value);
            if (!empty($value)) {
                $keywords[$key] = $value;
            } else {
                unset($keywords[$key]);
            }
        }
    }
    $keywords = array_unique($keywords);
    $count = -1;
    if (
        $user['id'] != 2
        &&
        !in_array(3, $application['session']->get('groups'))
        &&
        !in_array(4, $application['session']->get('groups'))
        &&
        in_array(5, $application['session']->get('groups'))
    ) {
        $query = <<<EOD
SELECT COUNT(`id`) AS `count`
FROM `tools_kns_keywords`
INNER JOIN `tools_kns_requests` ON `keywords`.`request_id` = `requests`.`id`
WHERE
    `tools_kns_requests`.`user_id` = ?
    AND
    DATE(`tools_kns_requests`.`timestamp`) = ?
EOD;
        $record = $application['db']->fetchAssoc(
            $query, array($user['id'], date('Y-m-d'))
        );
        $limit = 10;
        if ($record['count'] >= $limit) {
            $count = 0;
            $keywords = array();
        } else {
            $keywords = array_slice($keywords, 0, $limit - $record['count']);
            $count = $limit - $record['count'];
        }
    }

    return array($count, $keywords);
}

function get_contents($application, $user, $logo) {
    $file_path = sprintf(
        '%s/%s', get_path($application, $user, 'logos'), $logo
    );
    if (is_file($file_path)) {
        return sprintf(
            'data:%s;base64,%s',
            mime_content_type($file_path),
            base64_encode(file_get_contents($file_path))
        );
    }

    return '';
}

function get_csv($application, $user, $id) {
    list($request, $keywords) = get_request_and_keywords(
        $application, $user, $id
    );

    $stream = fopen('data://text/plain,', 'w+');
    fputcsv($stream, array(
        'Keyword',
        'Buyer Behavior',
        'Competition',
        'Optimization',
        sprintf('Spend (%s)', get_currency($request['country'])),
        sprintf('Avg. Price (%s)', get_currency($request['country'])),
        'Avg. Length',
        'Score',
    ));
    if ($keywords) {
        foreach ($keywords as $keyword) {
            fputcsv($stream, array(
                $keyword['string'],
                $keyword['contents']['buyer_behavior'][1],
                $keyword['contents']['competition'][1],
                $keyword['contents']['optimization'][1],
                $keyword['contents']['spend'][0][1],
                $keyword['contents']['average_price'][1],
                $keyword['contents']['average_length'][1],
                $keyword['contents']['score'][1],
            ));
        }
    }
    rewind($stream);
    $contents = stream_get_contents($stream);
    fclose($stream);

    return $contents;
}

function get_currency($country) {
    if ($country == 'co.jp') {
        return '¥';
    }
    if ($country == 'co.uk') {
        return '£';
    }
    if ($country == 'de') {
        return '€';
    }

    return '$';
}

function get_groups($application) {
    $array = array();
    $user = $application['session']->get('user');
    $records = $application['db']->fetchAll(
        'SELECT `group_id` FROM `wp_groups_user_group` WHERE `user_id` = ?',
        array($user['id'])
    );
    if (!empty($records)) {
        foreach ($records as $record) {
            $array[] = $record['group_id'];
        }
    }

    return $array;
}

function get_logos($application, $user) {
    $array = array();
    $path = get_path($application, $user, 'logos');
    $resource = dir($path);
    while (false !== ($file_name = $resource->read())) {
        if ($file_name == '.') {
            continue;
        }
        if ($file_name == '..') {
            continue;
        }
        $getimagesize = getimagesize(sprintf('%s/%s', $path, $file_name));
        $array[] = array(
            'dimensions' => sprintf(
                '%dx%d', $getimagesize[0], $getimagesize[1]
            ),
            'file_name' => $file_name,
        );
    }
    $resource->close();
    usort($array, 'usort_logos');

    return $array;
}

function get_part($subject, $body) {
    return str_replace(
        array('{{ subject }}', '{{ body }}'),
        array($subject, nl2br($body)),
        file_get_contents(sprintf('%s/templates/email.twig', __DIR__))
    );
}

function get_path($application, $user, $directory) {
    $path = sprintf('%s/files/%d/%s', __DIR__, $user['id'], $directory);
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }

    return $path;
}

function get_pdf($application, $user, $logo, $id, $variables) {
    list($request, $keywords) = get_request_and_keywords(
        $application, $user, $id
    );
    usort($keywords, 'usort_keywords_1');
    if ($keywords) {
        $keywords = array_slice($keywords, 0, 100);
        foreach ($keywords as $key => $value) {
            if (is_array($keywords[$key]['contents']['items'])) {
                $keywords[$key]['contents']['items'] = array_slice(
                    $keywords[$key]['contents']['items'], 0, 25
                );
            }
        }
    }
    if (is_development()) {
        $contents = 'PDF';
    } else {
        $file_path = tempnam(sprintf('%s/tmp', __DIR__), 'weasyprint-');
        file_put_contents(
            $file_path,
            $application['twig']->render('views/kns_detailed.twig', array(
                'currency' => get_currency($request['country']),
                'is_pdf' => false,
                'keywords' => $keywords,
                'logo' => get_contents($application, $user, $logo),
            ))
        );
        $contents = shell_exec(sprintf(
            '%s/weasyprint --format pdf %s -',
            $variables['virtualenv'],
            escapeshellarg($file_path)
        ));
        unlink($file_path);
    }

    return $contents;
}

function get_popular_searches($application) {
    $appearances = array(
        'last 7 days' => 0,
        'last 30 days' => 0,
    );
    $query = <<<EOD
SELECT COUNT(DISTINCT `date_and_time`) AS `count`
FROM `tools_ps_trends`
WHERE `date_and_time` >= NOW() - INTERVAL 7 DAY
EOD;
    $row = $application['db']->fetchAssoc($query);
    $appearances['last 7 days'] = $row['count'];
    $query = <<<EOD
SELECT COUNT(DISTINCT `date_and_time`) AS `count`
FROM `tools_ps_trends`
WHERE `date_and_time` >= NOW() - INTERVAL 30 DAY
EOD;
    $row = $application['db']->fetchAssoc($query);
    $appearances['last 30 days'] = $row['count'];
    $query = <<<EOD
SELECT *
FROM `tools_ps_books`
INNER JOIN
    `tools_ps_trends` ON `tools_ps_books`.`id` = `tools_ps_trends`.`book_id`
WHERE `tools_ps_trends`.`date_and_time` IN (
    SELECT MAX(`date_and_time`)
    FROM `tools_ps_trends`
)
ORDER BY `tools_ps_books`.`title` ASC
EOD;
    $popular_searches = $application['db']->fetchAll($query);
    foreach ($popular_searches as $key => $value) {
        $popular_searches[$key]['amazon_best_sellers_rank'] = json_decode(
            $popular_searches[$key]['amazon_best_sellers_rank'], true
        );
        asort(
            $popular_searches[$key]['amazon_best_sellers_rank'], SORT_NUMERIC
        );
        $query = <<<EOD
SELECT COUNT(id) AS `count`
FROM `tools_ps_trends`
WHERE `book_id` = ? AND `date_and_time` >= NOW() - INTERVAL 7 DAY
EOD;
        $row_1 = $application['db']->fetchAssoc($query, array(
            $popular_searches[$key]['book_id'],
        ));
        $query = <<<EOD
SELECT COUNT(id) AS `count`
FROM `tools_ps_trends`
WHERE `book_id` = ? AND `date_and_time` >= NOW() - INTERVAL 30 DAY
EOD;
        $row_2 = $application['db']->fetchAssoc($query, array(
            $popular_searches[$key]['book_id'],
        ));
        $popular_searches[$key]['appearances'] = array(
            'last 7 days' => (
                $row_1['count'] * 100.00
            ) / $appearances['last 7 days'],
            'last 30 days' => (
                $row_2['count'] * 100.00
            ) / $appearances['last 30 days'],
        );
    }

    return $popular_searches;
}

function get_requests($application, $user) {
    $query = <<<EOD
SELECT *
FROM `tools_kns_requests`
WHERE `user_id` = ?
ORDER BY `timestamp` DESC
EOD;
    $requests = $application['db']->fetchAll($query, array($user['id']));
    if ($requests) {
        $query_preview = <<<EOD
SELECT `string`
FROM `tools_kns_keywords`
WHERE `request_id` = ?
ORDER BY `id` ASC
LIMIT 5
OFFSET 0
EOD;
        $query_keywords_1 = <<<EOD
SELECT COUNT(`id`) AS `count`
FROM `tools_kns_keywords`
WHERE `request_id` = ?
EOD;
        $query_keywords_2 = <<<EOD
SELECT COUNT(`tools_kns_keywords`.`id`) AS `count`
FROM `tools_kns_keywords`
LEFT JOIN `tools_kns_requests` ON (
    `tools_kns_requests`.`id` = `tools_kns_keywords`.`request_id`
)
WHERE (
    `tools_kns_keywords`.`request_id` = ?
    AND
    `tools_kns_keywords`.`contents` IS NULL
    AND
    `tools_kns_requests`.`timestamp` < (NOW() - INTERVAL 5 HOUR)
)
EOD;
        foreach ($requests as $key => $value) {
            $requests[$key]['preview'] = array();
            $records = $application['db']->fetchAll(
                $query_preview, array($value['id'])
            );
            if ($records) {
                foreach ($records as $record) {
                    $requests[$key]['preview'][] = get_truncated_text(
                        $record['string'], 10
                    );
                }
            }
            $requests[$key]['preview'] = implode(
                ', ', $requests[$key]['preview']
            );
            $record_1 = $application['db']->fetchAssoc(
                $query_keywords_1, array($value['id'])
            );
            $record_2 = $application['db']->fetchAssoc(
                $query_keywords_2, array($value['id'])
            );
            $requests[$key]['keywords'] = array(
                $record_1['count'],
                number_format($record_1['count'], 0, '.', ','),
                $record_2['count'],
                number_format($record_2['count'], 0, '.', ','),
            );
            $old = new DateTime(date('Y-m-d H:i:s'));
            $new = new DateTime($requests[$key]['timestamp']);
            $new->add(new DateInterval('P30D'));
            $interval = $old->diff($new);
            $requests[$key]['expires_in'] = $interval->format('%R%a days');
        }
    }

    return $requests;
}

function get_request_and_keywords($application, $user, $id) {
    $query = <<<EOD
SELECT *
FROM `tools_kns_requests`
WHERE `id` = ? AND `user_id` = ?
EOD;
    $request = $application['db']->fetchAssoc(
        $query, array($id, $user['id'])
    );
    $keywords = array();
    if ($request) {
        $query = <<<EOD
SELECT *
FROM `tools_kns_keywords`
WHERE `request_id` = ?
EOD;
        $keywords = $application['db']->fetchAll($query, array($id));
        if ($keywords) {
            foreach ($keywords as $key => $value) {
                $keywords[$key]['contents'] = json_decode(
                    $keywords[$key]['contents'], true
                );
            }
        }
    }

    return array($request, $keywords);
}

function get_sections($application) {
    $sections = array();
    $query = <<<EOD
SELECT *
FROM `tools_ce_sections`
ORDER BY `id` ASC
EOD;
    $rows = $application['db']->fetchAll($query);
    if ($rows) {
        foreach ($rows as $row) {
            $sections[] = array($row['id'], $row['title']);
        }
    }

    return $sections;
}

function get_truncated_text($string, $length) {
    if (strlen($string) > $length) {
        return rtrim(substr($string, 0, $length)) . '...';
    }

    return $string;
}

function get_user($application) {
    if (is_development()) {
        return array(
            'email' => 'administrator@administrator.com',
            'id' => 1,
        );
    }
    if ($application['session']->get('transfer')) {
        return array(
            'email' => 'administrator@administrator.com',
            'id' => $application['session']->get('transfer'),
        );
    }
    if (!empty($_COOKIE)) {
        foreach ($_COOKIE as $key => $value) {
            $value = explode('|', $value);
            if (strpos($key, 'wordpress_logged_in_') !== false) {
                $query = <<<EOD
SELECT `ID`, `user_email` FROM `wp_users` WHERE `user_login` = ?
EOD;
                $record = $application['db']->fetchAssoc(
                    $query, array($value[0])
                );
                if (!empty($record)) {
                    return array(
                        'email' => $record['user_email'],
                        'id' => $record['ID'],
                    );
                }
            }
        }
    }

    return array(
        'email' => '',
        'id' => 0,
    );
}

function has_aks($groups) {
    return has_groups($groups, array(2, 5));
}

function has_kns($groups) {
    return has_groups($groups, array(3, 4, 5));
}

function has_groups($one, $two) {
    if (!empty($one)) {
        foreach ($one as $key => $value) {
            if (in_array($value, $two)) {
                return true;
            }
        }
    }

    return false;
}

function has_new_features($user) {
    return in_array($user['id'], array(1, 2, 3, 76));
}

function has_statistics($user) {
    return in_array($user['id'], array(1, 2, 3));
}

function is_development() {
    return $_SERVER['SERVER_PORT'] == '5000';
}

if (php_sapi_name() === 'cli-server') {
    if (is_file(sprintf(
        '%s/%s',
        __DIR__,
        preg_replace('#(\?.*)$#', '', $_SERVER['REQUEST_URI'])
    ))) {
        return false;
    }
}

require_once sprintf('%s/vendor/autoload.php', __DIR__);

use Doctrine\DBAL\Logging\DebugStack;
use Silex\Application;
use Silex\Provider\DoctrineServiceProvider;
use Silex\Provider\MonologServiceProvider;
use Silex\Provider\SessionServiceProvider;
use Silex\Provider\SwiftmailerServiceProvider;
use Silex\Provider\TwigServiceProvider;
use Silex\Provider\UrlGeneratorServiceProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

$variables = json_decode(file_get_contents(sprintf(
    '%s/variables.json', __DIR__
)), true);

$application = new Application();

$application['debug'] = $variables['application']['debug'];

$application->register(new DoctrineServiceProvider(), array(
    'db.options' => array(
        'charset' => 'utf8',
        'dbname' => $variables['mysql']['database'],
        'driver' => 'pdo_mysql',
        'host' => $variables['mysql']['host'],
        'password' => $variables['mysql']['password'],
        'user' => $variables['mysql']['user'],
    ),
));
$application->register(new SessionServiceProvider(array(
    'session.storage.save_path' => sprintf('%s/tmp', __DIR__),
)));
$application->register(new SwiftmailerServiceProvider(), array(
    'swiftmailer.options' => array(
        'auth_mode' => $variables['smtp']['auth_mode'],
        'encryption' => $variables['smtp']['encryption'],
        'host' => $variables['smtp']['host'],
        'password' => $variables['smtp']['password'],
        'port' => $variables['smtp']['port'],
        'username' => $variables['smtp']['username'],
    ),
));
$application->register(new TwigServiceProvider(), array(
    'twig.path' => sprintf('%s/templates', __DIR__),
));
$application->register(new UrlGeneratorServiceProvider());

$application['mailer'] = $application->share(function ($application) {
    return new \Swift_Mailer($application['swiftmailer.transport']);
});

if ($application['debug']) {
    $application->register(new MonologServiceProvider(), array(
        'monolog.logfile' => sprintf('%s/development.log', __DIR__),
    ));
    $logger = new DebugStack();
    $application->extend(
        'db.config',
        function($configuration) use ($logger) {
            $configuration->setSQLLogger($logger);

            return $configuration;
        }
    );
    $application->finish(function () use ($application, $logger) {
        foreach ($logger->queries as $query) {
            $application['monolog']->debug(
                $query['sql'],
                array(
                    'params' => $query['params'],
                    'types' => $query['types'],
                )
            );
        }
    });
}

$application->before(function (Request $request) use ($application) {
    $application['session']->set('user', get_user($application));
    $application['session']->set('groups', get_groups($application));
    $application['twig']->addGlobal(
        'user', $application['session']->get('user')
    );
    $application['twig']->addGlobal(
        'groups', $application['session']->get('groups')
    );
    $application['twig']->addFunction(
        'has_aks', new \Twig_Function_Function('has_aks')
    );
    $application['twig']->addFunction(
        'has_kns', new \Twig_Function_Function('has_kns')
    );
    $application['twig']->addFunction(
        'has_new_features', new \Twig_Function_Function('has_new_features')
    );
    $application['twig']->addFunction(
        'has_statistics', new \Twig_Function_Function('has_statistics')
    );
});

$before_aks = function () use ($application) {
    if (!has_aks($application['session']->get('groups'))) {
        return $application->redirect(
            $application['url_generator']->generate('dashboard')
        );
    }
};

$before_kns = function () use ($application) {
    if (!has_kns($application['session']->get('groups'))) {
        return $application->redirect(
            $application['url_generator']->generate('dashboard')
        );
    }
};

$before_statistics = function () use ($application) {
    if (!has_statistics($application['session']->get('user'))) {
        return $application->redirect(
            $application['url_generator']->generate('dashboard')
        );
    }
};

$before_new_features = function () use ($application) {
    if (!has_new_features($application['session']->get('user'))) {
        return $application->redirect(
            $application['url_generator']->generate('dashboard')
        );
    }
};

$application->match('/', function () use ($application) {
    return $application->redirect(
        $application['url_generator']->generate('dashboard')
    );
})
->method('GET');

$application->match('/transfer/{id}', function ($id) use ($application) {
    $application['session']->set('transfer', intval($id));
    return $application->redirect(
        $application['url_generator']->generate('dashboard')
    );
})
->bind('transfer')
->method('GET');

$application->match('/dashboard', function () use ($application) {
    return $application['twig']->render('views/dashboard.twig', array(
        'popular_searches' => array_slice(
            get_popular_searches($application), 0, 3
        ),
    ));
})
->bind('dashboard')
->method('GET');

$application->match('/kns/overview', function () use ($application) {
    $user = $application['session']->get('user');
    $requests = get_requests($application, $user);

    return $application['twig']->render('views/kns_overview.twig', array(
        'requests' => $requests,
    ));
})
->before($before_kns)
->bind('kns_overview')
->method('GET');

$application->match('/kns/add', function () use ($application) {
    $user = $application['session']->get('user');
    list($count, $_) = get_count_and_keywords($application, $user, '');

    return $application['twig']->render('views/kns_add.twig', array(
        'count' => $count,
        'countries' => array(
            'com' => 'US',
            'co.uk' => 'UK',
            'es' => 'Spain',
            'fr' => 'France',
            'it' => 'Italy',
            'com.br' => 'Brazil',
            'ca' => 'Canada',
            'de' => 'Germany',
            'co.jp' => 'Japan',
        ),
    ));
})
->before($before_kns)
->bind('kns_add')
->method('GET');

$application->match(
    '/kns/process',
    function (Request $request) use ($application) {
        $user = $application['session']->get('user');
        list($count, $keywords) = get_count_and_keywords(
            $application, $user, $request->get('keywords')
        );
        if (!$count OR !$keywords) {
            return $application->redirect(
                $application['url_generator']->generate('kns_add')
            );
        }
        $application['db']->insert('tools_kns_requests', array(
            'country' => $request->get('country'),
            'timestamp' => date('Y-m-d H:i:s'),
            'user_id' => $user['id'],
        ));
        $id = $application['db']->lastInsertId();
        if ($keywords) {
            foreach ($keywords as $keyword) {
                $application['db']->insert('tools_kns_keywords', array(
                    'request_id' => $id,
                    'string' => $keyword,
                ));
            }
        }

        return $application->redirect(
            $application['url_generator']->generate('kns_simple', array(
                'id' => $id,
            ))
        );
    }
)
->before($before_kns)
->bind('kns_process')
->method('POST');

$application->match(
    '/kns/{id}/simple',
    function (Request $request, $id) use ($application) {
        $user = $application['session']->get('user');
        list($request, $keywords) = get_request_and_keywords(
            $application, $user, $id
        );
        if (!$request OR !$keywords) {
            return $application->redirect(
                $application['url_generator']->generate('kns_overview')
            );
        }

        return $application['twig']->render('views/kns_simple.twig', array(
            'currency' => get_currency($request['country']),
            'email' => $user['email'],
            'keywords' => $keywords,
            'logos' => get_logos($application, $user),
            'request' => $request,
        ));
    }
)
->before($before_kns)
->bind('kns_simple')
->method('GET');

$application->match(
    '/kns/{id}/detailed',
    function (Request $request, $id) use ($application) {
        $user = $application['session']->get('user');
        list($request, $keywords) = get_request_and_keywords(
            $application, $user, $id
        );
        if (!$request OR !$keywords) {
            return $application->redirect(
                $application['url_generator']->generate('kns_overview')
            );
        }
        usort($keywords, 'usort_keywords_1');

        return $application['twig']->render('views/kns_detailed.twig', array(
            'currency' => get_currency($request['country']),
            'is_pdf' => true,
            'keywords' => $keywords,
            'logo' => '',
        ));
    }
)
->before($before_kns)
->bind('kns_detailed')
->method('GET');

$application->match(
    '/kns/{id}/csv',
    function (Request $request, $id) use ($application) {
        $user = $application['session']->get('user');
        $csv = get_csv($application, $user, $id);
        $stream = function () use ($csv) {
            echo $csv;
        };

        return $application->stream($stream, 200, array(
            'Content-Disposition' => sprintf(
                'attachment; filename="simple_report_%s.csv"', date('m_d_Y')
            ),
            'Content-Length' => strlen($csv),
            'Content-Type' => 'text/csv',
            'Expires' => '0',
            'Pragma' => 'no-cache',
        ));
    }
)
->before($before_kns)
->bind('kns_csv')
->method('GET');

$application->match(
    '/kns/{id}/pdf',
    function (Request $request, $id) use ($application, $variables) {
        $user = $application['session']->get('user');
        $pdf = get_pdf(
            $application, $user, $request->get('logo'), $id, $variables
        );
        $stream = function () use ($pdf) {
            echo $pdf;
        };

        return $application->stream($stream, 200, array(
            'Content-Disposition' => sprintf(
                'attachment; filename="detailed_report_%s.pdf"', date('m_d_Y')
            ),
            'Content-Length' => strlen($pdf),
            'Content-Type' => 'application/pdf',
            'Expires' => '0',
            'Pragma' => 'no-cache',
        ));
    }
)
->before($before_kns)
->bind('kns_pdf')
->method('GET');

$application->match(
    '/kns/{id}/xhr',
    function (Request $request, $id) use ($application) {
        $user = $application['session']->get('user');
        list($request, $keywords) = get_request_and_keywords(
            $application, $user, $id
        );

        if ($keywords) {
            foreach ($keywords as $key => $value) {
                if ($keywords[$key]['contents']) {
                    unset($keywords[$key]['contents']['items']);
                    if ($keywords[$key]['contents']['score'][1] !== 'N/A') {
                        $keywords[$key]['position'] = 1;
                    } else {
                        $keywords[$key]['position'] = 2;
                    }
                } else {
                    $keywords[$key]['position'] = 3;
                }
            }
        }

        usort($keywords, 'usort_keywords_2');

        return new Response(json_encode($keywords, JSON_NUMERIC_CHECK));
    }
)
->before($before_kns)
->bind('kns_xhr')
->method('POST');

$application->match(
    '/kns/{id}/email',
    function (Request $request, $id) use ($application, $variables) {
        $user = $application['session']->get('user');
        $subject = 'Your Kindle Niche Sidekick report is ready!';
        $body = <<<EOD
We have attached your report in simple and detailed formats. The detailed
report includes our recommendations along with important information about each
keyword.

If you have any questions at all please don't hesitate to contact
reports@perfectsidekick.com
EOD;
        try {
            $message = \Swift_Message::newInstance()
                ->addPart(get_part($subject, $body), 'text/html')
                ->attach(\Swift_Attachment::newInstance(
                    get_csv($application, $user, $id),
                    sprintf('simple_report_%s.csv', date('m_d_Y')),
                    'text/csv'
                ))
                ->attach(\Swift_Attachment::newInstance(
                    get_pdf(
                        $application,
                        $user,
                        $request->get('logo'),
                        $id,
                        $variables
                    ),
                    sprintf('detailed_report_%s.pdf', date('m_d_Y')),
                    'application/pdf'
                ))
                ->setBody(trim($body))
                ->setFrom(array(
                    'reports@perfectsidekick.com',
                ))
                ->setSubject($subject)
                ->setTo(array($request->get('email')));
            $application['mailer']->send($message);
        } catch (Exception $exception ) {
        }

        return new Response();
    }
)
->before($before_kns)
->bind('kns_email')
->method('POST');

$application->match(
    '/kns/{id}/delete',
    function (Request $request, $id) use ($application) {
        $user = $application['session']->get('user');
        list($request_, $keywords) = get_request_and_keywords(
            $application, $user, $id
        );
        if (!$request_ OR !$keywords) {
            return $application->redirect(
                $application['url_generator']->generate('kns_overview')
            );
        }
        if ($request->isMethod('POST')) {
            $application['db']->executeUpdate(
                'DELETE FROM `tools_kns_requests` WHERE `ID` = ?',
                array($id)
            );
            $application['session']->getFlashBag()->add(
                'success', array('The report was deleted successfully.')
            );
            return $application->redirect(
                $application['url_generator']->generate('kns_overview')
            );
        }
        return $application['twig']->render('views/kns_delete.twig', array(
            'id' => $id,
        ));
    }
)
->before($before_kns)
->bind('kns_delete')
->method('GET|POST');

$application->match('/logos/overview', function () use ($application) {
    $user = $application['session']->get('user');

    return $application['twig']->render('views/logos_overview.twig', array(
        'logos' => get_logos($application, $user),
    ));
})
->before($before_kns)
->bind('logos_overview')
->method('GET');

$application->match(
    '/logos/view',
    function (Request $request) use ($application) {
        $user = $application['session']->get('user');
        $stream = function () use ($application, $request, $user) {
            readfile(sprintf(
                '%s/%s',
                get_path($application, $user, 'logos'),
                $request->get('file_name')
            ));
        };

        return $application->stream($stream, 200, array(
            'Content-Type' => 'image/png',
        ));
    }
)
->before($before_kns)
->bind('logos_view')
->method('GET');

$application->match(
    '/logos/download',
    function (Request $request) use ($application) {
        $user = $application['session']->get('user');
        $file_path = sprintf(
            '%s/%s',
            get_path($application, $user, 'logos'),
            $request->get('file_name')
        );
        $stream = function () use ($file_path) {
            readfile($file_path);
        };

        return $application->stream($stream, 200, array(
            'Content-Disposition' => sprintf(
                'attachment; filename="%s"', $request->get('file_name')
            ),
            'Content-length' => filesize($file_path),
            'Content-Type' => 'image/png',
        ));
    }
)
->before($before_kns)
->bind('logos_download')
->method('GET');

$application->match(
    '/logos/add',
    function (Request $request) use ($application) {
        $user = $application['session']->get('user');

        $error = null;
        if ($request->isMethod('POST')) {
            $logo = $request->files->get('logo');
            if (
                $logo
                and
                in_array(
                    $logo->guessExtension(), array('png')
                )
            ) {
                $path = get_path($application, $user, 'logos');
                $logo->move($path, $logo->getClientOriginalName());
                $file_path = sprintf(
                    '%s/%s', $path, $logo->getClientOriginalName()
                );
                exec(sprintf(
                    'convert %s -resize x150 %s',
                    escapeshellarg($file_path),
                    escapeshellarg($file_path)
                ), $output, $return_var);
                $application['session']->getFlashBag()->add(
                    'success', array('The logo was uploaded successfully.')
                );

                return $application->redirect(
                    $application['url_generator']->generate('logos_overview')
                );
            } else {
                $error = 'Invalid Logo';
            }
        }

        return $application['twig']->render('views/logos_add.twig', array(
            'error' => $error,
        ));
    }
)
->before($before_kns)
->bind('logos_add')
->method('GET|POST');

$application->match(
    '/logos/delete',
    function (Request $request) use ($application) {
        $user = $application['session']->get('user');
        if ($request->isMethod('POST')) {
            unlink(sprintf(
                '%s/%s',
                get_path($application, $user, 'logos'),
                $request->get('file_name')
            ));
            $application['session']->getFlashBag()->add(
                'success', array('The logo was deleted successfully.')
            );

            return $application->redirect(
                $application['url_generator']->generate('logos_overview')
            );
        }

        return $application['twig']->render(
            'views/logos_delete.twig', array(
                'file_name' => $request->get('file_name'),
            )
        );
    }
)
->before($before_kns)
->bind('logos_delete')
->method('GET|POST');

$application->match('/aks', function () use ($application) {;
    return $application['twig']->render('views/aks.twig', array(
        'countries' => array(
            'com' => 'US',
            'co.uk' => 'UK',
            'es' => 'Spain',
            'fr' => 'France',
            'it' => 'Italy',
            'com.br' => 'Brazil',
            'ca' => 'Canada',
            'de' => 'Germany',
            'co.jp' => 'Japan',
        ),
        'search_aliases' => array(
            'aps' => 'All Departments',
            'digital-text' => 'Kindle Store',
            'instant-video' => 'Amazon Instant Video',
            'appliances' => 'Appliances',
            'mobile-apps' => 'Apps for Android',
            'arts-crafts' => 'Arts, Crafts &amp; Sewing',
            'automotive' => 'Automotive',
            'baby-products' => 'Baby',
            'beauty' => 'Beauty',
            'stripbooks' => 'Books',
            'mobile' => 'Cell Phones &amp; Accessories',
            'apparel' => 'Clothing &amp; Accessories',
            'collectibles' => 'Collectibles',
            'computers' => 'Computers',
            'financial' => 'Credit Cards',
            'electronics' => 'Electronics',
            'gift-cards' => 'Gift Cards Store',
            'grocery' => 'Grocery &amp; Gourmet Food',
            'hpc' => 'Health &amp; Personal Care',
            'garden' => 'Home &amp; Kitchen',
            'industrial' => 'Industrial &amp; Scientific',
            'jewelry' => 'Jewelry',
            'magazines' => 'Magazine Subscriptions',
            'movies-tv' => 'Movies &amp; TV',
            'digital-music' => 'MP3 Music',
            'popular' => 'Music',
            'mi' => 'Musical Instruments',
            'office-products' => 'Office Products',
            'lawngarden' => 'Patio, Lawn &amp; Garden',
            'pets' => 'Pet Supplies',
            'shoes' => 'Shoes',
            'software' => 'Software',
            'sporting' => 'Sports &amp; Outdoors',
            'tools' => 'Tools &amp; Home Improvement',
            'toys-and-games' => 'Toys &amp; Games',
            'videogames' => 'Video Games',
            'watches' => 'Watches',
        ),
    ));
})
->before($before_aks)
->bind('aks')
->method('GET');

$application->match(
    '/aks/xhr',
    function (Request $request) use ($application, $variables) {
        if (is_development()) {
            $output = array('["1", "2", "3"]');
        } else {
            ignore_user_abort(true);
            set_time_limit(0);
            exec(sprintf(
                '%s/python %s/scripts/aks.py %s %s %s %s %s',
                $variables['virtualenv'],
                __DIR__,
                escapeshellarg($request->get('country')),
                escapeshellarg($request->get('level')),
                escapeshellarg($request->get('mode')),
                escapeshellarg($request->get('keyword')),
                escapeshellarg($request->get('search_alias'))
            ), $output, $return_var);
        }
        return new Response(implode('', $output));
    }
)
->before($before_aks)
->bind('aks_xhr')
->method('POST');

$application->match(
    '/aks/download',
    function (Request $request) use ($application) {
        $json = json_decode($request->get('json'), true);
        $stream = function () use ($json) {
            echo implode("\n", $json['suggestions']);
        };
        return $application->stream($stream, 200, array(
            'Content-Disposition' => sprintf(
                'attachment; filename="%s.csv"', $json['keyword']
            ),
            'Content-Length' => strlen($json['suggestions']),
            'Content-Type' => 'text/csv',
            'Expires' => '0',
            'Pragma' => 'no-cache',
        ));
    }
)
->before($before_aks)
->bind('aks_download')
->method('POST');

$application->match(
    '/ce/overview',
    function (Request $request) use ($application) {
        return $application['twig']->render('views/ce_overview.twig', array(
            'categories' => array_merge(
                array(array(-1, 'All')),
                get_categories($application, 0, array())
            ),
            'sections' => get_sections($application),
        ));
    }
)
->before($before_new_features)
->bind('ce_overview')
->method('GET');

$application->match('/ce/xhr', function (Request $request) use ($application) {
    $contents = array(
        'books' => array(),
        'categories' => array(),
        'glance' => array(
            'absr' => 0,
            'estimated_sales_per_day' => 0,
            'price' => 0.00,
            'print_length' => 0,
            'review_average' => 0.00,
            'total_number_of_reviews' => 0,
            'words' => array(),
        ),
        'date' => 'N/A',
    );

    $category_id = intval($request->get('category_id'));
    $section_id = intval($request->get('section_id'));
    $print_length_1 = $request->get('print_length_1');
    $print_length_2 = intval($request->get('print_length_2'));
    $print_length_3 = intval($request->get('print_length_3'));
    $print_length_4 = intval($request->get('print_length_4'));
    $price_1 = $request->get('price_1');
    $price_2 = floatval($request->get('price_2'));
    $publication_date_1 = $request->get('publication_date_1');
    $publication_date_2 = $request->get('publication_date_2');
    $amazon_best_sellers_rank_1 = $request->get('amazon_best_sellers_rank_1');
    $amazon_best_sellers_rank_2 = intval(
        $request->get('amazon_best_sellers_rank_2')
    );
    $review_average_1 = $request->get('review_average_1');
    $review_average_2 = floatval($request->get('review_average_2'));
    $appearance_1 = $request->get('appearance_1');
    $appearance_2 = floatval($request->get('appearance_2'));
    $count = intval($request->get('count'));

    $appearances = array(
        'last 7 days' => 0,
        'last 30 days' => 0,
    );
    if ($category_id == -1) {
        $query = <<<EOD
SELECT COUNT(DISTINCT `date`) AS `count`
FROM `tools_ce_trends`
WHERE
    `category_id` > ?
    AND
    `section_id` = ?
    AND
    `date` >= CURDATE() - INTERVAL 7 DAY
EOD;
    } else {
        $query = <<<EOD
SELECT COUNT(DISTINCT `date`) AS `count`
FROM `tools_ce_trends`
WHERE
    `category_id` = ?
    AND
    `section_id` = ?
    AND
    `date` >= CURDATE() - INTERVAL 7 DAY
EOD;
    }
    $row = $application['db']->fetchAssoc($query, array(
        $category_id,
        $section_id,
    ));
    $appearances['last 7 days'] = $row['count'];
    if ($category_id == -1) {
        $query = <<<EOD
SELECT COUNT(DISTINCT `date`) AS `count`
FROM `tools_ce_trends`
WHERE
    `category_id` > ?
    AND
    `section_id` = ?
    AND
    `date` >= CURDATE() - INTERVAL 30 DAY
EOD;
    } else {
        $query = <<<EOD
SELECT COUNT(DISTINCT `date`) AS `count`
FROM `tools_ce_trends`
WHERE
    `category_id` = ?
    AND
    `section_id` = ?
    AND
    `date` >= CURDATE() - INTERVAL 30 DAY
EOD;
    }
    $row = $application['db']->fetchAssoc($query, array(
        $category_id,
        $section_id,
    ));
    $appearances['last 30 days'] = $row['count'];

    if ($category_id == -1) {
        $query = <<<EOD
SELECT MAX(`date`) AS `date`
FROM `tools_ce_trends`
WHERE
    `tools_ce_trends`.`category_id` > ?
    AND
    `tools_ce_trends`.`section_id` = ?
EOD;
    } else {
        $query = <<<EOD
SELECT MAX(`date`) AS `date`
FROM `tools_ce_trends`
WHERE
    `tools_ce_trends`.`category_id` = ?
    AND
    `tools_ce_trends`.`section_id` = ?
EOD;
    }
    $row = $application['db']->fetchAssoc($query, array(
        $category_id,
        $section_id,
    ));
    $contents['date'] = $row['date'];

    $query = <<<EOD
SELECT *
FROM `tools_ce_books`
INNER JOIN
    `tools_ce_trends` ON `tools_ce_books`.`id` = `tools_ce_trends`.`book_id`
WHERE %s
ORDER BY `tools_ce_trends`.`rank` ASC, `tools_ce_trends`.`category_id` ASC
LIMIT %d OFFSET 0
EOD;
    $conditions = array();
    $parameters = array();
    switch ($print_length_1) {
        case 'More Than':
            $conditions[] = '`tools_ce_books`.`print_length` > ?';
            $parameters[] = $print_length_2;
            break;
        case 'Less Than':
            $conditions[] = '`tools_ce_books`.`print_length` < ?';
            $parameters[] = $print_length_2;
            break;
        case 'Between':
            $conditions[] = <<<EOD
`tools_ce_books`.`print_length` >= ? AND `tools_ce_books`.`print_length` <= ?
EOD;
            $parameters[] = $print_length_3;
            $parameters[] = $print_length_4;
            break;
    }
    switch ($price_1) {
        case 'More Than':
            $conditions[] = '`tools_ce_books`.`price` > ?';
            $parameters[] = $price_2;
            break;
        case 'Less Than':
            $conditions[] = '`tools_ce_books`.`price` < ?';
            $parameters[] = $price_2;
            break;
    }
    switch ($publication_date_1) {
        case 'More Than':
            $conditions[] = '`tools_ce_books`.`publication_date` > ?';
            $parameters[] = $publication_date_2;
            break;
        case 'Less Than':
            $conditions[] = '`tools_ce_books`.`publication_date` < ?';
            $parameters[] = $publication_date_2;
            break;
    }
    switch ($review_average_1) {
        case 'More Than':
            $conditions[] = '`tools_ce_books`.`review_average` > ?';
            $parameters[] = $review_average_2;
            break;
        case 'Less Than':
            $conditions[] = '`tools_ce_books`.`review_average` < ?';
            $parameters[] = $review_average_2;
            break;
    }
    if ($category_id !== -1) {
        $conditions[] = '`tools_ce_trends`.`category_id` = ?';
        $parameters[] = $category_id;
    }
    $conditions[] = '`tools_ce_trends`.`section_id` = ?';
    $parameters[] = $section_id;
    $conditions[] = '`tools_ce_trends`.`date` = ?';
    $parameters[] = $contents['date'];
    $conditions = implode(' AND ', $conditions);
    $query = sprintf($query, $conditions, $count);
    $books = $application['db']->fetchAll($query, $parameters);
    if ($books) {
        foreach ($books as $book) {
            if ($category_id == -1) {
                $query = <<<EOD
SELECT COUNT(DISTINCT `date`) AS `count`
FROM `tools_ce_trends`
WHERE
    `category_id` > ?
    AND
    `section_id` = ?
    AND
    `book_id` = ?
    AND
    `date` >= CURDATE() - INTERVAL 7 DAY
EOD;
            } else {
                $query = <<<EOD
SELECT COUNT(DISTINCT `date`) AS `count`
FROM `tools_ce_trends`
WHERE
    `category_id` = ?
    AND
    `section_id` = ?
    AND
    `book_id` = ?
    AND
    `date` >= CURDATE() - INTERVAL 7 DAY
EOD;
            }
            $row_1 = $application['db']->fetchAssoc($query, array(
                $category_id,
                $section_id,
                $book['book_id'],
            ));
            if ($category_id == -1) {
                $query = <<<EOD
SELECT COUNT(DISTINCT `date`) AS `count`
FROM `tools_ce_trends`
WHERE
    `category_id` > ?
    AND
    `section_id` = ?
    AND
    `book_id` = ?
    AND
    `date` >= CURDATE() - INTERVAL 30 DAY
EOD;
            } else {
                $query = <<<EOD
SELECT COUNT(DISTINCT `date`) AS `count`
FROM `tools_ce_trends`
WHERE
    `category_id` = ?
    AND
    `section_id` = ?
    AND
    `book_id` = ?
    AND
    `date` >= CURDATE() - INTERVAL 30 DAY
EOD;
            }
            $row_2 = $application['db']->fetchAssoc($query, array(
                $category_id,
                $section_id,
                $book['book_id'],
            ));
            $book['appearances'] = array(
                'last 7 days' => (
                    $row_1['count'] * 100.00
                ) / $appearances['last 7 days'],
                'last 30 days' => (
                    $row_2['count'] * 100.00
                ) / $appearances['last 30 days'],
            );
            $book['amazon_best_sellers_rank'] = json_decode(
                $book['amazon_best_sellers_rank'], true
            );
            asort($book['amazon_best_sellers_rank'], SORT_NUMERIC);
            if ($amazon_best_sellers_rank_1 == 'More Than') {
                if (empty(
                    $book['amazon_best_sellers_rank']['Paid in Kindle Store']
                )) {
                    continue;
                }
                if (
                    $book['amazon_best_sellers_rank']['Paid in Kindle Store']
                    <=
                    $amazon_best_sellers_rank_2
                ) {
                    continue;
                }
            }
            if ($amazon_best_sellers_rank_1 == 'Less Than') {
                if (empty(
                    $book['amazon_best_sellers_rank']['Paid in Kindle Store']
                )) {
                    continue;
                }
                if (
                    $book['amazon_best_sellers_rank']['Paid in Kindle Store']
                    >=
                    $amazon_best_sellers_rank_2
                ) {
                    continue;
                }
            }
            if ($appearance_1 == 'More Than') {
                if ($book['appearances']['last 7 days'] <= $appearance_2) {
                    continue;
                }
            }
            if ($appearance_1 == 'Less Than') {
                if ($book['appearances']['last 7 days'] >= $appearance_2) {
                    continue;
                }
            }
            $book['amazon_best_sellers_rank_'] = 0;
            if (!empty(
                $book['amazon_best_sellers_rank']['Paid in Kindle Store']
            )) {
                $book[
                    'amazon_best_sellers_rank_'
                ] = $book['amazon_best_sellers_rank']['Paid in Kindle Store'];
                if ($book['amazon_best_sellers_rank_'] <= 25000) {
                    $contents['glance']['absr'] += 1;
                }
            }
            $book['category'] = get_category(
                $application, $book['category_id']
            );
            $contents['books'][] = $book;
            $contents['glance']['price'] += $book['price'];
            $contents[
                'glance'
            ]['estimated_sales_per_day'] += $book['estimated_sales_per_day'];
            $contents[
                'glance'
            ]['total_number_of_reviews'] += $book['total_number_of_reviews'];
            $contents['glance']['review_average'] += $book['review_average'];
            $contents['glance']['print_length'] += $book['print_length'];
            $words = preg_split('/[^A-Za-z0-9]/', $book['title']);
            if ($words) {
                foreach ($words as $word) {
                    if (strlen($word) > 3) {
                        $contents['glance']['words'][] = $word;
                    }
                }
            }
            if ($book['amazon_best_sellers_rank']) {
                foreach ($book['amazon_best_sellers_rank'] as $key => $value) {
                    if (
                        !preg_match('/^Paid in Kindle Store/', $key)
                        AND
                        !preg_match('/^Kindle Store > Kindle eBooks/', $key)
                    ) {
                        continue;
                    }
                    $key = str_replace('Kindle Store > ', '', $key);
                    if (empty($contents['categories'][$key])) {
                        $contents['categories'][$key] = 0;
                    }
                    $contents['categories'][$key] += 1;
                }
            }
        }
    }
    $cs = array();
    if ($contents['categories']) {
        foreach ($contents['categories'] as $key => $value) {
            if ($value > 1) {
                $cs[] = array(
                    'frequency' => $value,
                    'title' => $key,
                );
            }
        }
    }
    $contents['categories'] = $cs;
    usort($contents['categories'], 'usort_categories');

    $count = count($contents['books']);

    $contents['glance']['price'] /= $count;
    $contents['glance']['estimated_sales_per_day'] /= $count;
    $contents['glance']['total_number_of_reviews']  /= $count;
    $contents['glance']['review_average'] /= $count;
    $contents['glance']['print_length'] /= $count;
    $contents['glance']['words'] = array_count_values(
        $contents['glance']['words']
    );
    arsort($contents['glance']['words']);
    $words = array();
    $contents['glance']['words'] = array_slice(
        $contents['glance']['words'], 0, 10
    );
    if ($contents['glance']['words']) {
        foreach ($contents['glance']['words'] as $key => $value) {
            $words[] = array($key, $value);
        }
    }
    $contents['glance']['words'] = $words;

    return new Response(json_encode($contents, JSON_NUMERIC_CHECK));
})
->before($before_new_features)
->bind('ce_xhr')
->method('POST');

$application->match(
    '/feedback',
    function (Request $request) use ($application) {
        $error = '';
        if ($request->isMethod('POST')) {
            $body = $request->get('body');
            if (!empty($body)) {
                if (!is_development()) {
                    $user = $application['session']->get('user');
                    $subject = sprintf(
                        'ZonSidekick Feedback from %s', $user['email']
                    );
                    try {
                        $message = \Swift_Message::newInstance()
                            ->setBody(trim($request->get('body')))
                            ->setFrom(array(
                                $application[
                                    'swiftmailer.options'
                                ]['username'],
                            ))
                            ->setSubject($subject)
                            ->setTo(array(
                                'ncroan@gmail.com',
                                'mahendrakalkura@gmail.com',
                            ));
                        $application['mailer']->send($message);
                    } catch (Exception $exception ) {
                    }
                }
                $application['session']->getFlashBag()->add(
                    'success',
                    array('Your feedback has been sent successfully.')
                );
                return $application->redirect(
                    $application['url_generator']->generate('feedback')
                );
            } else {
                $error = 'Invalid Message';
            }
        }

        return $application['twig']->render('views/feedback.twig', array(
            'error' => $error,
        ));
    }
)
->bind('feedback')
->method('GET|POST');

$application->match(
    '/profile',
    function (Request $request) use ($application) {
        $user = $application['session']->get('user');

        $error = null;
        $email = $user['email'];

        if ($request->isMethod('POST')) {
            $email = $request->get('email');
            if (!empty($email)) {
                $application['db']->executeUpdate(
                    'UPDATE `wp_users` SET `user_email` = ? WHERE `ID` = ?',
                    array($email, $user['id'])
                );
                $application['session']->getFlashBag()->add(
                    'success', array('Your profile was updated successfully.')
                );
                return $application->redirect(
                    $application['url_generator']->generate('profile')
                );
            } else {
                $error = 'Invalid Email';
            }
        }

        return $application['twig']->render('views/profile.twig', array(
            'email' => $email,
            'error' => $error,
        ));
    }
)
->bind('profile')
->method('GET|POST');

$application->match(
    '/statistics',
    function (Request $request) use ($application) {
        $items = array();
        $total = array(
            'keywords_1' => 0,
            'keywords_2' => 0,
            'requests' => 0,
        );
        $query = <<<EOD
SELECT `ID` AS `id`, `display_name`
FROM `wp_users`
ORDER BY `ID` ASC
EOD;
        $records = $application['db']->fetchAll($query);
        if (!empty($records)) {
            foreach ($records as $record) {
                $requests = get_requests($application, $record);
                $r = count($requests);
                $k_1 = 0;
                $k_2 = 0;
                if ($requests) {
                    foreach ($requests as $request) {
                        $k_1 += $request['keywords'][0];
                        $k_2 += $request['keywords'][2];
                    }
                }
                $items[] = array(
                    'id' => $record['id'],
                    'display_name' => $record['display_name'],
                    'keywords_1' => number_format($k_1, 0, '.', ','),
                    'keywords_2' => number_format($k_2, 0, '.', ','),
                    'requests' => number_format($r, 0, '.', ','),
                );
                $total['keywords_1'] += $k_1;
                $total['keywords_2'] += $k_2;
                $total['requests'] += $r;
            }
        }

        return $application['twig']->render('views/statistics.twig', array(
            'items' => $items,
            'keywords_1' => number_format($total['keywords_1'], 0, '.', ','),
            'keywords_2' => number_format($total['keywords_2'], 0, '.', ','),
            'requests' => number_format($total['requests'], 0, '.', ','),
        ));
    }
)
->before($before_statistics)
->bind('statistics')
->method('GET');

$application->match(
    '/ps',
    function (Request $request) use ($application) {
        return $application['twig']->render('views/ps.twig', array(
            'popular_searches' => get_popular_searches($application),
        ));
    }
)
->before($before_new_features)
->bind('ps')
->method('GET');

$application->run();
