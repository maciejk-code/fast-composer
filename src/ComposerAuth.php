<?php
namespace FastComposer;

use Composer\Factory;
use Composer\IO\NullIO;

/**
 * Credentials Composer would use for an HTTPS VCS URL (auth.json of the project and of
 * COMPOSER_HOME, COMPOSER_AUTH), handed to Git the way Composer's Git utility shapes them.
 *
 * They are passed as an `Authorization` header through GIT_CONFIG_* environment variables:
 * never written to disk and, unlike Composer's user:password@host URLs, not visible in the
 * process list.
 */
final class ComposerAuth
{
    private NullIO $io;

    public function __construct(string $projectDir)
    {
        if (!InProcessComposer::loadClasses()) {
            throw new \RuntimeException('Composer classes are not available');
        }
        $this->io = new NullIO();
        $config = Factory::createConfig($this->io, $projectDir);
        // Same as Composer's Factory: a project auth.json overrides the global credentials.
        $localAuth = $projectDir.'/auth.json';
        if (is_file($localAuth)) {
            $config->merge(['config' => JsonFile::read($localAuth)], $localAuth);
        }
        $this->io->loadConfiguration($config);
    }

    /**
     * Extra environment for a Git process talking to $url, or [] when Composer has no
     * credentials for its host (or it is not an HTTP(S) URL).
     *
     * @return array<string,string>
     */
    public function gitEnvironment(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return [];
        }

        $origin = isset($parts['port']) ? $host.':'.$parts['port'] : $host;
        $key = $this->io->hasAuthentication($origin) ? $origin : ($this->io->hasAuthentication($host) ? $host : null);
        if ($key === null) {
            return [];
        }
        $auth = $this->io->getAuthentication($key);
        $username = (string) ($auth['username'] ?? '');
        $password = (string) ($auth['password'] ?? '');

        // Mirror Composer\Util\Git: GitLab tokens put the token type first, Bitbucket API tokens
        // use a fixed username, bearer tokens are sent as such.
        if ($password === 'bearer') {
            $header = 'Authorization: Bearer '.$username;
        } else {
            if (in_array($password, ['private-token', 'oauth2', 'gitlab-ci-token'], true)) {
                [$username, $password] = [$password, $username];
            } elseif (str_starts_with($password, 'ATAT')) {
                $username = 'x-bitbucket-api-token-auth';
            }
            $header = 'Authorization: Basic '.base64_encode($username.':'.$password);
        }

        $index = (int) (getenv('GIT_CONFIG_COUNT') ?: 0);
        return [
            'GIT_CONFIG_COUNT' => (string) ($index + 1),
            'GIT_CONFIG_KEY_'.$index => 'http.'.$scheme.'://'.$origin.'/.extraHeader',
            'GIT_CONFIG_VALUE_'.$index => $header,
        ];
    }
}
