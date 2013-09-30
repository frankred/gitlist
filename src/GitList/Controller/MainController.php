<?php

namespace GitList\Controller;

use Silex\Application;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;

class MainController implements ControllerProviderInterface
{
    public function connect(Application $app)
    {
        $route = $app['controllers_factory'];
		
		$route->get('/', function() use ($app) {
			if($app['public'] || $app['session'] -> has('user')){
	            $repositories = $app['git']->getRepositories($app['git.repos']);
	
	            return $app['twig']->render('index.twig', array(
	                'repositories'   => $repositories,
	                'user' => $app['session']->get('user')
	            ));
        	} else {
        		return $app['twig']->render('login.twig');
        	}
			
        })->bind('homepage');
		
		
		$route->post('/', function(Request $request) use ($app) {
        	if($app['public'] || $app['session']->has('user')){
	            $repositories = $app['git']->getRepositories($app['git.repos']);
	
	            return $app['twig']->render('index.twig', array(
	                'repositories'   => $repositories,
	                'user' => $app['session']->get('user')
	            ));
        	} else {
        		if($request -> get('login_name') == $app['login_name'] && $request -> get('login_password') == $app['login_password']){
					# login
					$app['session']->set('user', $app['login_name']);
					$repositories = $app['git']->getRepositories($app['git.repos']);
						return $app['twig']->render('index.twig', array(
		                	'repositories'   => $repositories,
	                		'user' => $app['session']->get('user')
		            	));					
				} else {
					# Error
					return $app['twig']->render('login.twig', array(
		                'error'   => 'Invalid username or password!',
		            ));
				}
        	}
        });
		
		$route->get('/logout', function(Request $request) use ($app) {
			$app['session']->remove('user');
			
            # Go back to calling page
            return $app->redirect($request->headers->get('Referer'));
        })->bind('user_logout');


        $route->get('/refresh', function(Request $request) use ($app) {
            # Go back to calling page
            return $app->redirect($request->headers->get('Referer'));
        })->bind('refresh');

        $route->get('{repo}/stats/{branch}', function($repo, $branch) use ($app) {
            $repository = $app['git']->getRepositoryFromName($app['git.repos'], $repo);

            if ($branch === null) {
                $branch = $repository->getHead();
            }

            $stats = $repository->getStatistics($branch);
            $authors = $repository->getAuthorStatistics($branch);

            return $app['twig']->render('stats.twig', array(
                'repo'           => $repo,
                'branch'         => $branch,
                'branches'       => $repository->getBranches(),
                'tags'           => $repository->getTags(),
                'stats'          => $stats,
                'authors'        => $authors,
                'user'			 => $app['session']->get('user')
            ));
        })->assert('repo', $app['util.routing']->getRepositoryRegex())
          ->assert('branch', $app['util.routing']->getBranchRegex())
          ->value('branch', null)
          ->bind('stats');

        $route->get('{repo}/{branch}/rss/', function($repo, $branch) use ($app) {
            $repository = $app['git']->getRepositoryFromName($app['git.repos'], $repo);

            if ($branch === null) {
                $branch = $repository->getHead();
            }

            $commits = $repository->getPaginatedCommits($branch);

            $html = $app['twig']->render('rss.twig', array(
                'repo'           => $repo,
                'branch'         => $branch,
                'commits'        => $commits,
            ));

            return new Response($html, 200, array('Content-Type' => 'application/rss+xml'));
        })->assert('repo', $app['util.routing']->getRepositoryRegex())
          ->assert('branch', $app['util.routing']->getBranchRegex())
          ->value('branch', null)
          ->bind('rss');

        return $route;
    }
}
