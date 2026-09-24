<?php
// HTTPS credentials come from Composer's own configuration (here COMPOSER_AUTH) and are shaped
// like Composer's Git utility does, as an Authorization header in GIT_CONFIG_* variables.
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
} finally {
    putenv('COMPOSER_AUTH');
    putenv('COMPOSER_HOME');
}
