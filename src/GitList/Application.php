<?php

namespace GitList;

use Silex\Application as SilexApplication;
use Silex\Provider\TwigServiceProvider;
use Silex\Provider\UrlGeneratorServiceProvider;
use Silex\Provider\SessionServiceProvider;
use Silex\Provider\SecurityServiceProvider;
use GitList\Provider\GitServiceProvider;
use GitList\Provider\RepositoryUtilServiceProvider;
use GitList\Provider\ViewUtilServiceProvider;
use GitList\Provider\RoutingUtilServiceProvider;

/**
 * GitList application.
 */
class Application extends SilexApplication {
	protected $path;

	/**
	 * Constructor initialize services.
	 *
	 * @param Config $config
	 * @param string $root   Base path of the application files (views, cache)
	 */
	public function __construct(Config $config, $root = null) {
		parent::__construct();
		$app = $this;
		$this -> path = realpath($root);

		$this['debug'] = $config -> get('app', 'debug');
		$this['cache'] = $config -> get('app', 'cache');
		$this['public'] = $config -> get('app', 'public');
		$this['filetypes'] = $config -> getSection('filetypes');
		$this['cache.archives'] = $this -> getCachePath() . 'archives';

		// Register services
		$this -> register(new TwigServiceProvider(), array(
			'twig.path' => $this -> getViewPath(),
			'twig.options' => $config -> get('app', 'cache') ? array('cache' => $this -> getCachePath() . 'views') : array(),
		));

		$repositories = $config -> get('git', 'repositories');

		$this -> register(new GitServiceProvider(), array(
			'git.client' => $config -> get('git', 'client'),
			'git.repos' => $repositories,
			'ini.file' => "config.ini",
			'git.hidden' => $config -> get('git', 'hidden') ? $config -> get('git', 'hidden') : array(),
			'git.default_branch' => $config -> get('git', 'default_branch') ? $config -> get('git', 'default_branch') : 'master',
		));

		$this -> register(new ViewUtilServiceProvider());
		$this -> register(new RepositoryUtilServiceProvider());
		$this -> register(new UrlGeneratorServiceProvider());
		$this -> register(new RoutingUtilServiceProvider());
		$this -> register(new SessionServiceProvider());

		$this['twig'] = $this -> share($this -> extend('twig', function($twig, $app) {
			$twig -> addFilter('htmlentities', new \Twig_Filter_Function('htmlentities'));
			$twig -> addFilter('md5', new \Twig_Filter_Function('md5'));

			return $twig;
		}));

		// Handle errors
		$this -> error(function(\Exception $e, $code) use ($app) {
			if ($app['debug']) {
				return;
			}

			return $app['twig'] -> render('error.twig', array('message' => $e -> getMessage(), ));
		});

		// Handle logins
		$admins = $config -> get('accounts', 'admins');
		$users = $config -> get('accounts', 'users');
		$admin_accounts = $this -> getPurifiedUserData($admins, 'ROLE_ADMIN');
		$users_accounts = $this -> getPurifiedUserData($users, 'ROLE_USER');
		$this['accounts'] = array_merge($users_accounts, $admin_accounts);

		if ($this['public'] == 'true') {

			// Public GitList, just protect /admin
			$admin_auth = array(
				'pattern' => '^/admin/',
				'form' => array(
					'login_path' => '/login_admin',
					'check_path' => '/admin/login_check'
				),
				'logout' => array(
					'logout_path' => '/admin/logout',
					'target_url' => '/'
				),
				'users' => $admin_accounts	// GitList is public, so only admin accounts are required
			);

			$app['security.firewalls'] = array(
				'admin' => $admin_auth,
				'misc' => array(
					'pattern' => '^/.*$',
					'anonymous' => true
				)
			);

			$app["twig"] -> addGlobal("login_type", 'admin_only');
		} else {
			// Private GitList, protect /admin and root /
			$app['security.role_hierarchy'] = array('ROLE_ADMIN' => array('ROLE_USER'));
			$app['security.access_rules'] = array(
				array(
					'^/loginu',
					'IS_AUTHENTICATED_ANONYMOUSLY'
				),
				array(
					'^/admin/',
					'ROLE_ADMIN'
				)
			);

			$user_auth = array(
				'pattern' => '^/.*$',
				'form' => array(
					'login_path' => '/loginu',
					'check_path' => '/login_check'
				),
				'logout' => array(
					'logout_path' => '/logout',
					'target_url' => '/'
				),
				'users' => $this['accounts']	// GitList is private, so all accounts are required
			);
			$login_noauth = array(
				'pattern' => '^/loginu',
				'anonymous' => true
			);
			$app['security.firewalls'] = array(
				'login' => $login_noauth,
				'all' => $user_auth,
				'misc' => array(
					'pattern' => '^/.*$',
					'anonymous' => true
				)
			);

			$app["twig"] -> addGlobal("login_type", 'all');
		}

		// Adding is_granted
		$function = new \Twig_SimpleFunction('is_granted', function($role) use ($app) {
			return $app['security'] -> isGranted($role);
		});
		$app['twig'] -> addFunction($function);

		$app -> register(new SecurityServiceProvider());
		$app -> boot();
	}

	public function getPath() {
		return $this -> path . DIRECTORY_SEPARATOR;
	}

	public function setPath($path) {
		$this -> path = $path;
		return $this;
	}

	public function getCachePath() {
		return $this -> path . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR;
	}

	public function getViewPath() {
		return $this -> path . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR;
	}

	private function getPurifiedUserData($accounts, $role) {
		$purifiedAccounts = array();

		foreach ($accounts as &$account) {
			$account_array = explode(":", $account);
			$name = $account_array[0];
			$password = $account_array[1];
			$purifiedAccounts[$name] = array(
				$role,
				$password
			);
		}
		return $purifiedAccounts;
	}

}
