<?php

namespace App\Controllers;

use App\Models\Ann;
use App\Models\Node;
use App\Models\Token;
use App\Models\User;
use App\Services\Config;
use App\Utils\Hash;
use App\Utils\Tools;
use App\Services\Auth;
use App\Utils\URL;

/**
 *  AppApiController
 */
class AppApiController extends BaseController
{

    public static function genQrStr($server, $port, $pwd, $method)
    {

        // method:password@hostname:port
        $txt = sprintf('%s:%s@%s:%u', $method, $pwd, $server, $port);
        // ss://base64(str)
        return sprintf('ss://%s', base64_encode($txt));
    }

    public function token($tokenStr, User $user, $expireTime)
    {
        $token = new Token();
        $token->token = $tokenStr;
        $token->user_id = $user->id;
        $token->create_time = time();
        $token->expire_time = $expireTime;
        if ($token->save()) return true;
        return false;
    }

    public function getToken($token)
    {
        $tokenModel = Token::where('token', $token)->where('expire_time', '>', time())->first();
        return $tokenModel ?: null;
    }

    public function newToken($request, $response, $args)
    {
        // $data = $request->post('sdf');
        $email = $request->getParam('email');

        $email = strtolower($email);
        $passwd = $request->getParam('passwd');

        // Handle Login
        $user = User::where('email', '=', $email)->first();

        if ($user == null) {
            $res['ret'] = 0;
            $res['msg'] = "401 邮箱或者密码错误";
            return $this->echoJson($response, $res);
        }

        if (!Hash::checkPassword($user->pass, $passwd)) {
            $res['ret'] = 0;
            $res['msg'] = "402 邮箱或者密码错误";
            return $this->echoJson($response, $res);
        }
        $tokenStr = Tools::genToken();
        $expireTime = time() + 3600 * 24 * 7;
        if ($this->token($tokenStr, $user, $expireTime)) {
            $res['ret'] = 1;
            $res['msg'] = "ok";
            $res['data']['token'] = $tokenStr;
            $res['data']['user_id'] = $user->id;
            return $this->echoJson($response, $res);
        }
        $res['ret'] = 0;
        $res['msg'] = "system error";
        return $this->echoJson($response, $res);
    }

    public function index($request, $response, $args)
    {
        $accessToken = $request->getParam('access_token');
        $token = $this->getToken($accessToken);
        if (!$token) return $response->withStatus(401)->withJson(['ret' => 0]);
        $user = User::find($token->user_id);
        $Ann = Ann::orderBy('date', 'desc')->first();
        return $this->echoJson($response, [
                'code' => 1,
                'user' => $user,
                'ann' => $Ann,
                'traffic' => [
                    "usagePercent" => $user->trafficUsagePercent(),
                    "total" => $user->enableTraffic(),
                    "used" => $user->usedTraffic(),
                    "unused" => $user->unusedTraffic(),
//                    "unusedBet" => $user->unusedTrafficBet(),
                ],
                'data' => static::nodes($user)]
        );
    }

    public function v2_index($request, $response, $args)
    {
        $accessToken = $request->getParam('access_token');
        $token = $this->getToken($accessToken);
        if (!$token) return $response->withStatus(401)->withJson(['ret' => 0]);
        $user = User::find($token->user_id);
        $Ann = Ann::orderBy('date', 'desc')->first();
        return $this->echoJson($response, [
                'code' => 1,
                'user' => $user,
                'ann' => $Ann,
                'traffic' => [
                    "usagePercent" => $user->trafficUsagePercent(),
                    "total" => $user->enableTraffic(),
                    "used" => $user->usedTraffic(),
                    "unused" => $user->unusedTraffic(),
//                    "unusedBet" => $user->unusedTrafficBet(),
                ],
                'data' => static::v2_nodes($user)]
        );
    }


    public static function v2_nodes($user)
    {
        $nodes = Node::whereIn('sort', [13])->where("type", "1")->where(
            function ($query) use ($user) {
                $query->where("node_group", "=", $user->node_group)
                    ->orWhere("node_group", "=", 0);
            }
        )->orderBy('name')->get();
        $tempArray = [];
        foreach ($nodes as $node) {
            if ($node->isNodeOnline()) {
                $v2server = URL::getV2Url($user, $node, 1);
                array_push($tempArray, array_merge($v2server, [
                    "name" => $node->name,
                    "info" => $node->info,
                    "status" => $node->status,
                    "server" => $v2server['add'],
                    "node_ip" => $v2server['add'],
                    "online_user" => $node->getOnlineUserCount(),
                    "node_class" => $node->node_class,
                    "traffic_rate" => $node->traffic_rate,
                    "server_port" => $v2server['add'],
                    "group" => Config::get('appName'),
                ]));
            }
        }
        return $tempArray;
    }

    public function ssr_index($request, $response, $args)
    {
        $accessToken = $request->getParam('access_token');
        $token = $this->getToken($accessToken);
        if (!$token) return $response->withStatus(401)->withJson(['ret' => 0]);
        $user = User::find($token->user_id);
        $Ann = Ann::orderBy('date', 'desc')->first();
        return $this->echoJson($response, [
                'code' => 1,
                'user' => $user,
                'ann' => $Ann,
                'traffic' => [
                    "usagePercent" => $user->trafficUsagePercent(),
                    "total" => $user->enableTraffic(),
                    "used" => $user->usedTraffic(),
                    "unused" => $user->unusedTraffic(),
//                    "unusedBet" => $user->unusedTrafficBet(),
                ],
                'data' => static::ssr_nodes($user)]
        );
    }

    public static function nodes($user)
    {
        $nodes = Node::where('type', 1)->where(function ($query) {
            $query->where('mu_only', -1)->orWhere('mu_only', 0);
        })->orderBy('sort')->get();
        $data = [];
        foreach ($nodes as $n) {
            if ($n->sort == 0 || $n->sort == 7 || $n->sort == 8 ||
                $n->sort == 10 || $n->sort == 11) {
                $n->online_user = $n->getOnlineUserCount();
            } else {
                $n->online_user = -1;
            }
            $n->ssQr = static::genQrStr($n->server, $user->port, $user->passwd, $user->method);
//            if ($n->node_bandwidth_limit >= $n->node_bandwidth)
            if ($n->isNodeOnline() !== false) array_push($data, $n);
        }
        return $data;
    }

    public static function deviation($node, $port)
    {
        if (preg_match('/#/', $node->name)) $port += explode('#', $node->name)[1];
        else if (preg_match('/#/', $node->info)) $port += explode('#', $node->info)[1];
        else if (preg_match('/;/', $node->server)) {
            $port_tmp = explode('port=', $node->server)[1];
            if (preg_match('/#/', $node->server)) {
                $port_exp = explode('+', $port_tmp);
                foreach ($port_exp as $item) {
                    $port_item = explode('#', $item);
                    if ($port == $port_item[0]) $port = $port_item[1];
                }
            } else $port += $port_tmp;
        }
        return $port;
    }

    public static function ssr_nodes($user)
    {
        $nodes = Node::whereIn('sort', [0, 10])->where("type", "1")->where(
            function ($query) use ($user) {
                $query->where("node_group", "=", $user->node_group)
                    ->orWhere("node_group", "=", 0);
            }
        )->orderBy('name')->get();

        $mu_nodes = Node::where('sort', 9)->where("type", "1")->where(
            function ($query) use ($user) {
                $query->where("node_group", "=", $user->node_group)
                    ->orWhere("node_group", "=", 0);
            }
        )->orderBy('name')->get();

        $temparray = array();
        foreach ($nodes as $node) {
            if ($node->isNodeOnline() !== false) {
                $node->name = preg_replace('/-/', ' - ', $node->name);
                if ($node->mu_only == -1 || $node->mu_only == 0) {
                    array_push($temparray, array(
                            "name" => $node->name,
                            "info" => $node->info,
                            "status" => $node->status,
                            "server" => $node->server,
                            "node_ip" => $node->node_ip,
                            "online_user" => $node->getOnlineUserCount(),
                            "node_class" => $node->node_class,
                            "protocol_param" => "",
                            "traffic_rate" => $node->traffic_rate,
                            "server_port" => $user->port,
                            "method" => ($node->custom_method == 1 ? $user->method : $node->method),
                            "obfs" => str_replace("_compatible", "", (($node->custom_rss == 1 && !($user->obfs == 'plain' && $user->protocol == 'origin')) ? $user->obfs : "plain")),
                            "obfsparam" => (($node->custom_rss == 1 && !($user->obfs == 'plain' && $user->protocol == 'origin')) ? $user->obfs_param : ""),
                            "remarks_base64" => base64_encode($node->name),
                            "password" => $user->passwd,
                            "group" => Config::get('appName'),
                            "protocol" => str_replace("_compatible", "", (($node->custom_rss == 1 && !($user->obfs == 'plain' && $user->protocol == 'origin')) ? $user->protocol : "origin")),
                        )
                    );
                }

                if ($node->mu_only == 1) {
                    foreach ($mu_nodes as $mu_node) {
                        $mu_user = User::where('port', '=', $mu_node->server)->first();
                        $mu_user->obfs_param = $user->getMuMd5();
                        $port = static::deviation($node, $mu_node->server);
                        array_push($temparray, array("name" => explode('#', $node->name)[0],
                            "info" => explode('#', $node->info)[0] . " - " . $port . " 端口",
                            "status" => $node->status,
                            "server" => explode(';', $node->server)[0],
                            "node_ip" => $node->node_ip,
                            "online_user" => $node->getOnlineUserCount(),
                            "node_class" => $node->node_class,
                            "protocol_param" => "{$user->id}:{$user->passwd}",
                            "traffic_rate" => $node->traffic_rate,
                            "server_port" => $port,
                            "method" => $mu_user->method,
                            "group" => Config::get('appName'),
                            "obfs" => str_replace("_compatible", "", (($node->custom_rss == 1 && !($mu_user->obfs == 'plain' && $mu_user->protocol == 'origin')) ? $mu_user->obfs : "plain")),
                            "obfsparam" => (($node->custom_rss == 1 && !($mu_user->obfs == 'plain' && $mu_user->protocol == 'origin')) ? $mu_user->obfs_param : ""),
                            "remarks_base64" => base64_encode($node->name . "- " . $mu_node->server . " 单端口"),
                            "password" => $mu_user->passwd,
                            "tcp_over_udp" => false,
                            "udp_over_tcp" => false,
                            "protocol" => str_replace("_compatible", "", (($node->custom_rss == 1 && !($mu_user->obfs == 'plain' && $mu_user->protocol == 'origin')) ? $mu_user->protocol : "origin")),
                            "obfs_udp" => false,
                            "enable" => true));
                    }
                }
            }
        }
        return $temparray;
    }


    public function doCheckIn($request, $response, $args)
    {
        $accessToken = $request->getParam('access_token');
        $token = $this->getToken($accessToken);
        if (!$token) {
            $res['ret'] = 0;
            $res['msg'] = "token is null";
            return $this->echoJson($response, $res);
        }
        $user = User::find($token->user_id);
        if (strtotime($user->expire_in) < time()) {
            $res['ret'] = 0;
            $res['msg'] = "您的账户已过期，无法签到。";
            return $this->echoJson($response, $res);
        }

        if (!$user->isAbleToCheckin()) {
            $res['ret'] = 0;
            $res['msg'] = "您似乎已经签到过了...";
            return $this->echoJson($response, $res);
        }
        $traffic = rand(Config::get('checkinMin'), Config::get('checkinMax'));
        $user->transfer_enable = $user->transfer_enable + Tools::toMB($traffic);
        $user->last_check_in_time = time();
        $user->save();
        $res['msg'] = sprintf("获得了 %d MB流量.", $traffic);
        $res['unflowtraffic'] = $user->transfer_enable;
        $res['traffic'] = Tools::flowAutoShow($user->transfer_enable);
        $res['ret'] = 1;
        return $this->echoJson($response, $res);
    }

    public function redirect($request, $response, $args)
    {
        $accessToken = $request->getParam('access_token');
        $token = $this->getToken($accessToken);
        if (!$token) {
            $res['ret'] = 0;
            $res['msg'] = "token is null";
            return $this->echoJson($response, $res);
        }
        $url = $request->getQueryParams()["target"];
        $user = User::find($token->user_id);
        $time = 3600 * 24;
        Auth::login($user->id, $time);
        return $response->withRedirect($url);
    }

}
