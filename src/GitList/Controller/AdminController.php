<?php

namespace GitList\Controller;

use GitList;
use Silex\Application;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\Session;

class AdminController implements ControllerProviderInterface {

	public function connect(Application $app) {
		$route = $app['controllers_factory'];

		# Logout
		$route -> get('/admin/logout', function() use ($app) {
			# Install PHP 5.4.11 or newer if you want to use invalidate(), Quickfix: $app['session'] -> clear()
			# $app['session'] -> invalidate();
			$app['session'] -> remove('admin');
			
			
			return $app['twig'] -> render('admin_login.twig', array());
		}) -> bind('logout');

		# Login
		$route -> get('/admin/login', function(Request $request) use ($app) {
			if ($app['session'] -> has('admin')) {
				return $this -> adminpanel($app);
			} else {
				return $app['twig'] -> render('admin_login.twig', array());
			}
		}) -> bind('login');

		# Login (form submit)
		$route -> post('/admin/login', function(Request $request) use ($app) {
			$name = $request -> get('gitlist_name');
			$password = $request -> get('gitlist_password');

			# check login data
			if ($app['admin.name'] == $name && $app['admin.password'] == $password) {
				# log admin in
				$app['session'] -> set('admin', $app['admin.name']);

				# goto adminpanel
				return $this -> adminpanel($app);

			} else {
				return $app['twig'] -> render('admin_login.twig', array('error' => 'Wrong login! Try again.'));
			}
		});

		# Adminpanel
		$route -> get('/admin/admin', function(Request $request) use ($app) {
			return $this -> adminpanel($app);
		}) -> bind('adminpanel');

		# AJAX: Adminpanel: Delete a repositories path out of the configuration
		$route -> get('/admin/admin/delete/{repospathBase64}', function($repospathBase64) use ($app) {
			if ($app['session'] -> has('admin')) {

				$config = GitList\Config::fromFile($app -> getPath() . 'config.ini');

				$repospathToDelete = base64_decode($repospathBase64);
				$repospaths = $app['git.repos'];

				# delete it out!
				$index = array_search($repospathToDelete, $repospaths);
				if ($index !== FALSE) {
					unset($repospaths[$index]);
				} else {
					return new Response(json_encode(array('type' => 'error', 'message' => 'Can\'t delete! Repositories list <strong>' . $repospathToDelete . '<strong> not found.')));
				}

				# keep debug and cache in ini, php-ini false proptery is the same like an empty property
				$config -> set('app', 'cache', (empty($data['app']['cache']) || $data['app']['cache'] == 'false') ? 'false' : 'true');
				$config -> set('app', 'debug', (empty($data['app']['debug']) || $data['app']['debug'] == 'false') ? 'false' : 'true');
				$config -> set('app', 'public', (empty($data['app']['public']) || $data['app']['public'] == 'false') ? 'false' : 'true');

				# save to config.ini
				$config -> set('git', 'repositories', $repospaths);
				$config -> toFile($app -> getPath() . 'config.ini');

				return new Response(json_encode(array('type' => 'success', 'message' => 'Repositories list <strong>' . $repospathToDelete . '</strong> was deleted successfully from your GitList.')), 200);
			} else {
				return new Response(json_encode(array('type' => 'error', 'message' => 'You are not logged in!')));
			}
		});

		# Adminpanel: Change configuration
		$route -> post('/admin/admin', function(Request $request) use ($app) {
			if ($app['session'] -> has('admin')) {
				# read in current config
				$config = GitList\Config::fromFile($app -> getPath() . 'config.ini');

				# transform to true or false
				$debug = $request -> get('debug') == 'yes' ? 'true' : 'false';
				$cache = $request -> get('cache') == 'yes' ? 'true' : 'false';
				$public = $request -> get('public') == 'yes' ? 'true' : 'false';

				# create new config
				$data = array();

				# add abostrohpe if it is a windows system
				if ($this -> isWindows()) {
					$data['git']['client'] = "\"" . $request -> get('gitpath') . "\"";
				} else {
					$data['git']['client'] = $request -> get('gitpath');
				}

				$data['git']['default_branch'] = $request -> get('defaultbranch');
				$data['git']['repositories'] = $app['git.repos'];
				$data['app']['debug'] = $debug;
				$data['app']['cache'] = $cache;
				$data['app']['public'] = $public;
				$data['app']['login_name'] = $request -> get('login_name');
				$data['app']['login_password'] = $request -> get('login_password');

				# add new repository path

				# check if repository exists
				$newpath = $request -> get('newpath');

				$notification = 'saved';

				if (!empty($newpath)) {
					try {
						$options['path'] = $app['git.client'];
						$options['hidden'] = $app['git.hidden'];
						$options['ini.file'] = $app['ini.file'];
						$options['default_branch'] = $app['git.default_branch'];
						$client = new GitList\Git\Client($options);
						$client -> getRepositories(array($newpath));

						# if repository path has a git repos, add new repositories path to other paths
						array_push($data['git']['repositories'], $newpath);
					} catch (\RuntimeException $e) {
						$notification = 'error_wrong_path';
					}
				}

				$config -> addAndOverwrite($data);
				$config -> toFile($app -> getPath() . 'config.ini');

				return $app['twig'] -> render('admin.twig', 
					array('repositorypaths' => $this -> repositoriesAddBase64Attribute($data['git']['repositories']),
						 'client' => trim($data['git']['client'], '"'), 
						 'default_branch' => $data['git']['default_branch'], 
						 'cache' => (empty($data['app']['cache']) || $data['app']['cache'] == 'false') ? false : true, 
						 'debug' => (empty($data['app']['debug']) || $data['app']['debug'] == 'false') ? false : true,
						 'public' => (empty($data['app']['public']) || $data['app']['public'] == 'false') ? false : true,  
						 'login_name' => $data['app']['login_name'],
						 'login_password' => $data['app']['login_password'],						 
						 'notification' => $notification, 
						 'newpath' => $newpath)
					);
			} else {
				return $app['twig'] -> render('admin_login.twig', array());
			}
		}) -> bind('savetoconfig');

		return $route;
	}

	public function repositoriesAddBase64Attribute($repositories) {
		$repositories_with_base64 = array();
		foreach ($repositories as $value) {
			$repo['path'] = $value;
			$repo['base64'] = base64_encode($value);
			$repositories_with_base64[] = $repo;
		}
		return $repositories_with_base64;
	}

	private function adminpanel($app) {
		if ($app['session'] -> has('admin')) {
			return $app['twig'] -> render('admin.twig', 
				array(
					'repositorypaths' => $this -> repositoriesAddBase64Attribute($app['git.repos']),
					'client' => trim($app['git.client'], '"'),
					'default_branch' => $app['git.default_branch'],
					'cache' => (empty($app['twig.options']['cache']) || $app['twig.options']['cache'] == 'false') ? false : true,
					'debug' => (empty($app['debug']) || $app['debug'] == 'false') ? false : true,
					'public' => (empty($app['public']) || $app['public'] == 'false') ? false : true,
					'login_name' => $app['login_name'],
					'login_password' => $app['login_password'],
					'admin' => $app['session'] -> get('admin')
				)
			);
		} else {
			return $app['twig'] -> render('admin_login.twig', array());
		}
	}

	private function isWindows() {
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
