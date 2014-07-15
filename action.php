<?php
/**
 * search Logger Plugin
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     i-net software <tools@inetsoftware.de>
 * @author     Gerry Weissbach <gweissbach@inetsoftware.de>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'action.php');

class action_plugin_searchcombinedgoogle extends DokuWiki_Action_Plugin {

	var $googleresults = null;
	var $query = null;

	/**
	 * for backward compatability
	 * @see inc/DokuWiki_Plugin#getInfo()
	 */
    function getInfo(){
        if ( method_exists(parent, 'getInfo')) {
            $info = parent::getInfo();
        }
        return is_array($info) ? $info : confToHash(dirname(__FILE__).'/plugin.info.txt');
    }

	function register(Doku_Event_Handler $controller) {

		// Log Query
		$controller->register_hook('SEARCH_QUERY_FULLPAGE', 'AFTER', $this, 'searchlogger__log');
		$controller->register_hook('FULLTEXT_SNIPPET_CREATE', 'BEFORE',  $this, 'searchlogger__getSnippet');
		$controller->register_hook('SEARCH_QUERY_PAGE_TITLE', 'BEFORE',  $this, 'searchlogger__getPageTitle');
	}
	
	function searchlogger__getPageTitle(&$event, $args)
	{
	   if ( !empty($this->googleresults[$event->data['id']]) )
	   {
	       $event->data['name'] = $this->googleresults[$event->data['id']]->title;
	       $event->data['id'] = $this->googleresults[$event->data['id']]->path;
	       
	       return true;
	   }
	   
	   return false;
	}

	function searchlogger__getSnippet(&$event, $args) {
		if ( !empty($this->googleresults[$event->data['id']]) )
		{
			$event->data['text'] = strip_tags($this->googleresults[$event->data['id']]->content);
		} else {
			$event->data['text'] = ''; //p_render('text', p_get_instructions($event->data['snippet']), $INFO);
		}
	}

	function searchlogger__log(&$event, $args) {
		global $ACT, $conf, $cache_wikifn;

		if ( $ACT == 'search' ) {
		    
			$currentResult = $event->result;
			$site = $_SERVER['SERVER_NAME'];
			$url = "http://ajax.googleapis.com/ajax/services/search/web?v=1.0&q=" . urlencode($event->data['query'] . " site:" . $site) . "&key=" . $this->getConf('googleapikey') . "&userip=" . $_SERVER['REMOTE_ADDR'];
			
            if ( $url != $this->query )
            {
	       		$maxQueries = array(array( 'start' => 0 ),);
    			$results = array();
			
			     for( $i = 0; $i < count($maxQueries); $i++ ) {

    			 	$http = new DokuHTTPClient();
    	   		 	$http->timeout = 25; //max. 25 sec
			 		
    				$json = new JSON();
    
    				$tempResults = $json->decode($http->get($url . '&start=' . intval($maxQueries[$i]->start), $params, 'GET'));

    				if ( $tempResults->responseStatus != 200 ) {
    					continue;
    				}

    				if ( $i == 0 ) {
    					$maxQueries = $tempResults->responseData->cursor->pages;
    				}

    				$results = array_merge($results, $tempResults->responseData->results);
    			}
			
    			$event->result = array();
    			
    			$this->query = $url;

                $oldCache = $cache_wikifn;
                $cache_wikifn = array();

                foreach ( $results as $result ) {
                
                    if ( parse_url(urldecode($result->url), PHP_URL_QUERY) != '' )
                    {
                        continue; // Parameter ignorieren
                    }
                    
                    $result->path = parse_url(urldecode($result->url), PHP_URL_PATH);
                    $id = cleanID($result->path);
				
                    resolve_pageid(null,$id,$exists);
                    $this->googleresults[$id] = $result;
                    $event->result[$id] = intval($currentResult[$id]) == 0 ? 'unknown' : $currentResult[$id];
                    unset($currentResult[$id]);
                }
			
                $cache_wikifn = $oldCache;
                $event->result = array_merge($event->result, $currentResult);
            }
		}

		return true;
	}
}

//Setup VIM: ex: et ts=2 enc=utf-8 :