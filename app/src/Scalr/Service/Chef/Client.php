<?php

use http\QueryString;
use Scalr\System\Http\Client\Request;

class Scalr_Service_Chef_Client
    {
        private $chefServerUrl = '';
        private $key = '';
        private $username = '';
        private static $Chef;

        public static function getChef($url, $username, $privateKey)
        {
             self::$Chef = new Scalr_Service_Chef_Client($url, $username, $privateKey);
             return self::$Chef;
        }

        public function __construct($url, $username, $privateKey)
        {
            $this->chefServerUrl = $url;
            $this->username = $username;
            $this->key = $privateKey;
        }

        public function listEnvironments()
        {
            return $this->request("/environments", "GET");
        }

        public function listCookbooks($env = '')
        {
            if(empty($env))
                $retval = $this->request('/cookbooks', "GET");
            else
                $retval = $this->request("/environments/{$env}/cookbooks", "GET");
            return $retval;
        }

        public function getCookbook($name)
        {
            return $this->request("/cookbooks/{$name}", "GET");
        }

        public function listEnvironmentRecipes($env = '')
        {
            $env = ($env == '') ? "_default" : $env;
            return $this->request("/environments/{$env}/recipes", "GET");
        }
        
        public function listRecipes($cookbookName, $cookbookVersion = '_latest')
        {
            return $this->request("/cookbooks/{$cookbookName}/{$cookbookVersion}", "GET");
        }

        public function createClient($name)
        {
            $client = new stdClass();
            $client->name = $name;
            $client->admin = false;

            return $this->request("/clients", "POST", json_encode($client));
        }

        public function removeClient($name)
        {
            return $this->request("/clients/{$name}", "DELETE");
        }

        public function removeNode($name)
        {
            return $this->request("/nodes/{$name}", "DELETE");
        }

        public function listRoles()
        {
            return $this->request('/roles', "GET");
        }

        public function createRole($name, $description, $runList, $attributes, $environment = array())
        {
            $role = new stdClass();
            $role->name = $name;
            $role->chef_type = "role";
            $role->json_class = "Chef::Role";
            $role->default_attributes = new stdClass();
            $role->description = $description;
            $role->run_list = $runList;
            $role->override_attributes = empty($attributes) ? new stdClass() : $attributes;
            if (!empty($environment))
                $role->env_run_lists = $environment;

            return $this->request("/roles", "POST", json_encode($role));
        }

        public function updateRole($name, $description, $runList, $attributes, $environment = array())
        {
            $role = new stdClass();
            $role->name = $name;
            $role->chef_type = "role";
            $role->json_class = "Chef::Role";
            $role->default_attributes = new stdClass();
            $role->description = $description;
            $role->run_list = $runList;
            $role->override_attributes = empty($attributes) ? new stdClass() : $attributes;
            if (!empty($environment))
                $role->env_run_lists = $environment;
            return $this->request("/roles/{$name}", "PUT", json_encode($role));
        }

        public function removeRole($name)
        {
            return $this->request("/roles/{$name}", "DELETE");
        }

        public function getRole($name)
        {
            return $this->request("/roles/{$name}", "GET");
        }

        public function getClient ($name = '')
        {
            if ($name)
                return $this->request("/clients/{$name}", "GET");
            else
                return $this->request("/clients", "GET");

        }

        public function createEnvironment($name, $description, $cookbook, $attributes)
        {
            $env = new stdClass();
            $env->name = $name;
            $env->attributes = empty($attributes) ? new stdClass() : $attributes;
            $env->json_class = "Chef::Environment";
            $env->description = $description;
            $env->cookbook_versions = empty($cookbook) ? new stdClass() : $cookbook;
            $env->chef_type = "environment";
            return $this->request("/environments", "POST", json_encode($env));
        }

        public function getEnvironment ($name)
        {
            return $this->request("/environments/{$name}", "GET");
        }

        public function updateEnvironment($name, $description, $cookbook, $attributes)
        {
            $env = new stdClass();
            $env->name = $name;
            $env->attributes = empty($attributes) ? new stdClass() : $attributes;
            $env->json_class = "Chef::Environment";
            $env->description = $description;
            $env->cookbook_versions = empty($cookbook) ? new stdClass() : $cookbook;
            $env->chef_type = "environment";

            return $this->request("/environments/{$name}", "PUT", json_encode($env));
        }

         public function removeEnvironment($name)
         {
             return $this->request("/environments/{$name}", "DELETE");
         }

        private function request($path, $method, $data="")
        {
            $data = trim($data);
            $httpRequest = new Request();

            $fullUrl = "{$this->chefServerUrl}{$path}";
            $chunks = parse_url($fullUrl);

            if (in_array($method, ["POST", "PUT"]) && $data) {
                $httpRequest->append($data);
            }

            $httpRequest->setRequestUrl($fullUrl);
            $httpRequest->setRequestMethod($method);

            $tz = @date_default_timezone_get();
            date_default_timezone_set("UTC");
            $timestamp = date("Y-m-d\TH:i:s\Z");
            date_default_timezone_set($tz);

            $chunks['path'] = str_replace('//', '/', $chunks['path']);

            $hashedPath = base64_encode(sha1($chunks['path'], true));
            $hashedBody = base64_encode(sha1($data, true));
            $userId = $this->username;

            $str = "Method:{$method}\n" .
              "Hashed Path:{$hashedPath}\n" .
            "X-Ops-Content-Hash:{$hashedBody}\n" .
            "X-Ops-Timestamp:{$timestamp}\n" .
            "X-Ops-UserId:{$userId}";

            $headers = array(
                'x-ops-sign'	  	 	=> "algorithm=sha1;version=1.0",
                'x-chef-version'		=> "0.10.8",
                'x-ops-userid' 			=> $userId,
                'x-ops-timestamp' 		=> $timestamp,
                'x-ops-content-hash' 	=> $hashedBody,
                'content-type'			=> 'application/json',
                'accept'				=> 'application/json'
              );

              $r = array_merge($headers, $this->sign($str));

              $httpRequest->addHeaders($r);
              $response = \Scalr::getContainer()->http->sendRequest($httpRequest);

              if($response->getResponseCode() == 401)
                throw new Exception("Failed to authenticate as {$userId}. Ensure that your node_name and client key are correct.");
            if($response->getResponseCode() == 404)
                throw new Exception("Client not found or parameters are not valid");
            else if ($response->getResponseCode() <= 205) {
                $data = $response->getBody()->toString();
                $retval = empty($data) ? true : json_decode($data);
            }
            else if ($response->getResponseCode() >= 300 && $response->getResponseCode() < 400) {
                throw new Exception("Request to chef server failed. Chef server returned code {$response->getResponseCode()}. Redirect URL: {$response->getHeader("Location")}");
            }
            else if ($response->getResponseCode() > 400) {
                $data = $response->getBody()->toString();

                $msg = empty($data) ? "" : json_decode($data);
                if (is_array($msg->error))
                    $msg = $msg->error[0];
                elseif ($msg->error)
                    $msg = $msg->error;
                else
                    $msg = "Unknown error. Error code: {$response->getResponseCode()}";

                throw new Exception("Request to chef server failed with error: {$msg} ({$method} {$path})");
            } else {
                throw new Exception("Unexpected situation. Response code {$response->getResponseCode()}");
            }

            return $retval;
        }

        public function sign($string) {
            $crypt = "";
            $headers = array();
            $key = openssl_get_privatekey($this->key);

            @openssl_private_encrypt($string, $crypt, $key);

            $sigs = preg_split("/\n/", chunk_split(base64_encode($crypt), 60));

            for ($i = 1; $i < count($sigs); $i++) {
                if ($sigs[$i-1] != '')
                    $headers["x-ops-authorization-{$i}"] = trim($sigs[$i-1]);
            }

            return $headers;
        }
}