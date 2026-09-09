<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Tests;

use AzerioidPanel\Broker\Config;
use AzerioidPanel\Broker\FakeRuntime;
use AzerioidPanel\Broker\Os\DistroPaths;
use PHPUnit\Framework\TestCase;

final class DistroPathsTest extends TestCase
{
    public function test_el_mariadb_and_php_fpm_from_registry(): void
    {
        $rt = new FakeRuntime();
        $rt->files['/etc/os-release'] = "ID=almalinux\nVERSION_ID=\"9.8\"\n";
        $rt->files['/etc/my.cnf.d/mariadb-server.cnf'] = "[mysqld]\n";
        $rt->files['/etc/php.ini'] = "memory_limit = 128M\n";
        $rt->files['/run/php-fpm/www.sock'] = '';
        $this->loadRegistry($rt, 'mariadb', [
            'el' => [
                'unit_name' => 'mariadb',
                'paths' => ['server_cnf' => ['/etc/my.cnf.d/mariadb-server.cnf']],
            ],
        ]);
        $this->loadRegistry($rt, 'php-8.4', [
            'el' => [
                'unit_name' => 'php-fpm',
                'paths' => [
                    'php_ini' => ['/etc/php.ini'],
                    'fpm_socket' => ['/run/php-fpm/www.sock'],
                ],
            ],
        ]);
        $this->scriptLoad($rt, 'php-fpm', 'loaded');
        $this->scriptLoad($rt, 'php8.4-fpm', 'not-found');

        $layout = DistroPaths::for($rt, new Config());
        $this->assertSame('/etc/my.cnf.d/mariadb-server.cnf', $layout->mariadbServerCnf());
        $this->assertSame('php-fpm', $layout->phpFpmUnit('8.4'));
        $this->assertSame('/etc/php.ini', $layout->phpIni('8.4'));
        $this->assertSame('/run/php-fpm/www.sock', $layout->phpFpmUnixSocket('8.4'));
    }

    public function test_debian_paths_from_live_files_without_os_release(): void
    {
        $rt = new FakeRuntime();
        $rt->files['/etc/mysql/mariadb.conf.d/50-server.cnf'] = "[mysqld]\n";
        $rt->files['/etc/php/8.4/fpm/php.ini'] = "memory_limit = 128M\n";
        $rt->files['/run/php/php8.4-fpm.sock'] = '';

        $layout = DistroPaths::for($rt, new Config());
        $this->assertSame('/etc/mysql/mariadb.conf.d/50-server.cnf', $layout->mariadbServerCnf());
        $this->assertSame('/etc/php/8.4/fpm/php.ini', $layout->phpIni('8.4'));
        $this->assertSame('/run/php/php8.4-fpm.sock', $layout->phpFpmUnixSocket('8.4'));
    }

    public function test_el_apache_layout_from_registry_even_if_dir_missing(): void
    {
        $rt = new FakeRuntime();
        $rt->files['/etc/os-release'] = "ID=almalinux\nVERSION_ID=\"9.8\"\n";
        $this->loadRegistry($rt, 'apache', [
            'el' => [
                'unit_name' => 'httpd',
                'paths' => [
                    'vhost_dir' => '/etc/httpd/conf.d/vhost',
                    'vhost_available' => '/etc/httpd/conf.d/vhost',
                    'web_log_dir' => '/var/log/httpd',
                    'apache_ctl' => '/usr/sbin/apachectl',
                ],
            ],
        ]);
        $this->scriptLoad($rt, 'httpd', 'loaded');
        $this->scriptLoad($rt, 'apache2', 'not-found');

        $layout = DistroPaths::for($rt, new Config())->apacheSiteLayout();
        $this->assertSame('httpd', $layout['web_service']);
        $this->assertSame('/etc/httpd/conf.d/vhost', $layout['vhost_dir']);
        $this->assertSame('/var/log/httpd', $layout['web_log_dir']);
    }

    /** @param array<string, mixed> $distros */
    private function loadRegistry(FakeRuntime $rt, string $id, array $distros): void
    {
        $rt->files['/usr/local/lib/azerioid-panel/registry/components/' . $id . '.json'] = json_encode(
            ['id' => $id, 'distros' => $distros],
            JSON_THROW_ON_ERROR
        );
    }

    private function scriptLoad(FakeRuntime $rt, string $unit, string $state): void
    {
        $rt->script(
            ['/usr/bin/systemctl', 'show', $unit, '--property=LoadState', '--no-pager'],
            0,
            "LoadState={$state}\n"
        );
    }
}
