<?php

namespace GitList\Controller;

use GitList;
use Silex\Application;
use Silex\ControllerProviderInterface;
use Silex\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Security\Core\Encoder\MessageDigestPasswordEncoder;

class AdminController implements ControllerProviderInterface {

	public function connect(Application $app) {
		$notLoggedInMessage = 'You are not logged in!';

		$route = $app['controllers_factory'];

		# Adminpanel
		$route -> get('/admin/', function(Request $request) use ($app) {
			if ($app['security'] -> isGranted('ROLE_ADMIN')) {
				return self::adminpanel($app);
			} else {
				return $app['twig'] -> render('admin_access_denied.twig', array());
			}
		}) -> bind('adminpanel');

		# edit a single property - debug, cache, public
		$route -> post('/admin/edit/{group}/{property}', function($group, $property, Request $request) use ($app) {
			if ($app['security'] -> isGranted('ROLE_ADMIN')) {
				return self::editProperty($property, $request -> get('value'), $group, $app);
			}
			return self::loginError();
		});

		# add account - admins[], users[]
		$route -> post('/admin/add_account/{property}', function($property, Request $request) use ($app) {
			if ($app['security'] -> isGranted('ROLE_ADMIN')) {
				$group = 'accounts';
				$name = $request -> get('name');
				$password = $request -> get('password');
				$encoder = new MessageDigestPasswordEncoder();
				$password = $encoder -> encodePassword($password, '');
				$value = $name . ':' . $password;
				return self::addToPropertyArray($property, $value, $group, $app);
			}
			return self::loginError();
		});

		# remove account by username
		$route -> get('/admin/remove_account/{name}', function($name, Request $request) use ($app) {
			if ($app['security'] -> isGranted('ROLE_ADMIN')) {
				if (array_key_exists($name, $app['accounts'])) {
					$group = 'accounts';
					$user_array = $app['accounts'][$name];
					$password = $user_array[1];
					$role = $user_array[0];
					$value = $name . ":" . $password;
					$name = "";

					if ($role == "ROLE_ADMIN") {
						$name = 'admins';
					} else if ($role == "ROLE_USER") {
						$name = 'users';
					}
					return self::removeFromPropertyArray($name, $value, $group, $app);
				}

				return new Response(json_encode(array(
					'type' => 'error',
					'message' => 'Can not delete user. User does not exists!'
				)));
			}
			return self::loginError();
		});

		# add a repository path
		$route -> post('/admin/add_repo', function(Request $request) use ($app) {
			if ($app['security'] -> isGranted('ROLE_ADMIN')) {
				$newrepopath = $request -> get('newrepopath');

				// path empty
				if (empty($newrepopath)) {
					return new Response(json_encode(array(
						'type' => 'error',
						'message' => 'Repository path is empty!'
					)));
				}

				// path wrong
				try {
					$options['path'] = $app['git.client'];
					$options['hidden'] = $app['git.hidden'];
					$options['ini.file'] = $app['ini.file'];
					$options['default_branch'] = $app['git.default_branch'];
					$client = new GitList\Git\Client($options);
					$client -> getRepositories(array($newrepopath));
				} catch (\RuntimeException $e) {
					return new Response(json_encode(array(
						'type' => 'error',
						'message' => 'Repository path is wrong!'
					)));
				}

				$config = GitList\Config::fromFile($app -> getPath() . 'config.ini');
				$repositories = $config -> get('git', 'repositories');
				array_push($repositories, $newrepopath);
				$config -> set('git', 'repositories', $repositories);
				$config -> toFile($app -> getPath() . 'config.ini');

				return new Response(json_encode(array(
					'type' => 'succeed',
					'message' => 'The repository path was added to the config.ini'
				)));
			}

			return self::loginError();
		});

		# remove a repository path
		$route -> post('/admin/remove_repo', function(Request $request) use ($app) {
			if ($app['security'] -> isGranted('ROLE_ADMIN')) {
				$repo = $request -> get('repo');
				$repopaths = $app['git.repos'];

				$config = GitList\Config::fromFile($app -> getPath() . 'config.ini');

				# delete it
				$index = array_search($repo, $repopaths);
				if ($index !== FALSE) {
					unset($repopaths[$index]);
				} else {
					return new Response(json_encode(array(
						'type' => 'error',
						'message' => 'Can\'t delete! Repositories list <strong>' . $repo . '<strong> not found.'
					)));
				}

				$config -> set('git', 'repositories', $repopaths);
				$config -> toFile($app -> getPath() . 'config.ini');

				return new Response(json_encode(array(
					'type' => 'succeed',
					'message' => 'Repositories list <strong>' . $repo . '</strong> was deleted successfully from your GitList.'
				)));

			}

			return self::loginError();
		});
		return $route;
	}

	# helper method to edit a property
	private static function editProperty($name, $value, $group, $app) {
		$config = GitList\Config::fromFile($app -> getPath() . 'config.ini');
		$config -> set($group, $name, $value);

		// Git client path fix: On windows systems an addition abostrophe has to be added '"c:\asd"'
		if ($name == 'client') {
			if (self::isWindows()) {
				$config -> set($group, $name, "\"" . $value . "\"");
			}
			if (!file_exists($value)) {
				return new Response(json_encode(array(
					'type' => 'error',
					'message' => 'Path <strong>' . htmlspecialchars($value, ENT_QUOTES) . '</strong> does not exists!'
				)));
			} else if (is_dir($value)) {
				return new Response(json_encode(array(
					'type' => 'error',
					'message' => 'Path <strong>' . htmlspecialchars($value, ENT_QUOTES) . '</strong> is a directory!'
				)));
			}
		}

		$config -> toFile($app -> getPath() . 'config.ini');
		return new Response(json_encode(array(
			'type' => 'succeed',
			'message' => 'Property <strong>' . htmlspecialchars($name, ENT_QUOTES) . '</strong> successfully changed to <strong>' . htmlspecialchars($value, ENT_QUOTES) . '</strong> in config.ini!'
		)));
	}

	private static function addToPropertyArray($name, $value, $group, $app) {
		$config = GitList\Config::fromFile($app -> getPath() . 'config.ini');
		$property_array = $config -> get($group, $name);
		array_push($property_array, $value);

		$config -> set($group, $name, $property_array);
		$config -> toFile($app -> getPath() . 'config.ini');

		return new Response(json_encode(array(
			'type' => 'succeed',
			'message' => 'Property ' . $name . ' successfully added!',
			'array' => json_encode($property_array)
		)));
	}

	private static function removeFromPropertyArray($name, $value, $group, $app) {
		if ($app['security'] -> isGranted('ROLE_ADMIN')) {
			$config = GitList\Config::fromFile($app -> getPath() . 'config.ini');
			$property_array = $config -> get($group, $name);
			$property_array = array_diff($property_array, array($value));
			$config -> set($group, $name, $property_array);
			$config -> toFile($app -> getPath() . 'config.ini');
			return new Response(json_encode(array(
				'type' => 'succeed',
				'message' => 'Property ' . $name . ' successfully removed!'
			)));
		}
		return self::loginError();
	}

	private static function repositoriesAddBase64Attribute($repositories) {
		$repositories_with_base64 = array();
		foreach ($repositories as $value) {
			$repo['path'] = $value;
			$repo['base64'] = base64_encode($value);
			$repositories_with_base64[] = $repo;
		}
		return $repositories_with_base64;
	}

	private static function adminpanel($app) {
		return $app['twig'] -> render('admin.twig', array(
			'bind_admin_js' => 'true',
			'repositorypaths' => AdminController::repositoriesAddBase64Attribute($app['git.repos']),
			'client' => trim($app['git.client'], '"'),
			'default_branch' => $app['git.default_branch'],
			'cache' => (empty($app['cache']) || $app['cache'] == 'false') ? false : true,
			'debug' => (empty($app['debug']) || $app['debug'] == 'false') ? false : true,
			'public' => (empty($app['public']) || $app['public'] == 'false') ? false : true,
			'accounts' => $app['accounts']
		));
	}

	private static function loginError() {
		return new Response(json_encode(array(
			'type' => 'error',
			'message' => 'Error! Please login!'
		)));
	}

	private static function isWindows() {
		switch (PHP_OS) {
			case 'WIN32' :
			case 'WINNT' :
			case 'Windows' :
				return true;
			default :
				return false;
		}
	}

}
