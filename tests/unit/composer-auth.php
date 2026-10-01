<?php
// HTTPS credentials come from Composer's own configuration (here COMPOSER_AUTH) and are shaped
// like Composer's Git utility does, as an Authorization header in GIT_CONFIG_* variables. URLs
// Composer's secure-http refuses are refused before any network access.
use FastComposer\ComposerAuth;

putenv('COMPOSER_HOME='.$base.'/composer-home');
putenv('COMPOSER_AUTH='.json_encode([
    'github-oauth' => ['github.com' => 'ghtoken'],
    'gitlab-token' => ['gitlab.example.com' => 'gltoken'],
    'http-basic' => ['git.example.com:8443' => ['username' => 'deploy', 'password' => 's3cret']],
    'bearer' => ['bearer.example.com' => 'btoken'],
]));
try {
    $auth = new ComposerAuth($base);
    // The entry is appended after any GIT_CONFIG_COUNT entries already in the environment.
    $index = (int) (getenv('GIT_CONFIG_COUNT') ?: 0);
    $header = static fn (string $url): ?string => $auth->gitEnvironment($url)['GIT_CONFIG_VALUE_'.$index] ?? null;

    fc_assert($header('https://github.com/newsuk/private.git') === 'Authorization: Basic '.base64_encode('ghtoken:x-oauth-basic'), 'github-oauth');
    fc_assert($header('https://gitlab.example.com/group/repo.git') === 'Authorization: Basic '.base64_encode('private-token:gltoken'), 'gitlab-token');
    fc_assert($header('https://git.example.com:8443/repo.git') === 'Authorization: Basic '.base64_encode('deploy:s3cret'), 'http-basic with port');
    fc_assert($header('https://bearer.example.com/repo.git') === 'Authorization: Bearer btoken', 'bearer');
    fc_assert($auth->gitEnvironment('git@github.com:newsuk/private.git') === [], 'SSH URLs must not get HTTP credentials');
    fc_assert($auth->gitEnvironment('https://unknown.example.com/repo.git') === [], 'hosts without credentials get nothing');
    fc_assert(
        ($auth->gitEnvironment('https://github.com/newsuk/private.git')['GIT_CONFIG_KEY_'.$index] ?? null) === 'http.https://github.com/.extraHeader',
        'header must be scoped to the host'
    );

    $refused = static function (ComposerAuth $auth, string $url): bool {
        try {
            $auth->assertAllowed($url);
            return false;
        } catch (\RuntimeException) {
            return true;
        }
    };
    fc_assert($refused($auth, 'http://git.example.com/repo.git'), 'secure-http refuses http://');
    fc_assert($refused($auth, 'git://git.example.com/repo.git'), 'secure-http refuses git://');
    fc_assert(!$refused($auth, 'https://git.example.com/repo.git'), 'https:// is allowed');
    fc_assert(!$refused($auth, 'git@github.com:newsuk/private.git'), 'SSH URLs are allowed');
    fc_assert(!$refused($auth, $base), 'local paths are allowed');

    // Like Composer's Factory, the project's composer.json config counts: credentials there and
    // secure-http turned off.
    $project = new ComposerAuth($base, ['config' => [
        'secure-http' => false,
        'http-basic' => ['project.example.com' => ['username' => 'u', 'password' => 'p']],
    ]]);
    fc_assert(!$refused($project, 'http://git.example.com/repo.git'), 'secure-http false in composer.json allows http://');
    fc_assert(
        ($project->gitEnvironment('https://project.example.com/repo.git')['GIT_CONFIG_VALUE_'.$index] ?? null) === 'Authorization: Basic '.base64_encode('u:p'),
        'credentials from composer.json config'
    );
} finally {
    putenv('COMPOSER_AUTH');
    putenv('COMPOSER_HOME');
}
