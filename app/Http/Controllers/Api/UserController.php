<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\User;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class UserController extends Controller
{

    /**
     * sso web签名登录
     *
     * @param Request $request
     * @param $user
     * @return Application|RedirectResponse|Redirector
     */
    public function login(Request $request, $user)
    {
        $user = app(User::class)->where("email", $user)->firstOrFail();
        Auth::loginUsingId($user->id);
        return redirect('/');
    }

    /**
     * sso 登录
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {

        $request->validate([
            'email' => 'required|string'
        ]);
        return response()->json([
            "message" => "Success",
            "data" => Url::temporarySignedRoute('sso.login', now()->addMinutes(5), ["user" => $request->input("email")])
        ]);
    }

    /**
     * sso 批量保存用户
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function saveUser(Request $request): JsonResponse
    {
        set_time_limit(0);
        $postData = file_get_contents('php://input');
        $list = array_get(json_decode(preg_replace('/[\x00-\x1F]/', '', stripslashes($postData)), true), "data");
        if (!$list) {
            //error handle ,错误处理
            $ret = json_last_error();
        }

        if (is_array($list) && !empty($list)) {
//            foreach ($list as $key => $data) {
//                $user[] = app(User::class)->updateOrCreate(["email" => $data['email']],
//                    [
//                        'name' => $data['name'],
//                        'email' => $data['email'],
//                        'password' => '$2y$10$mnCqCEgzO4GnRsU3Xs.kzuJIuP8B93PlWmbA98xCK817JvpeWDLLa',//bcrypt("12345678"),
//                        'status' => $data['status'] ?? User::STATUS_ACTIVATED
//                    ]
//                );
//            }

            $saveData = [];
            foreach ($list as $key => $data) {
                $user = app(User::class)->where("email", $data['email'])->first();
                if ($user == null) {

                    array_push($saveData, [
                        'name' => $data['name'],
                        'email' => $data['email'],
                        'password' => '$2y$10$mnCqCEgzO4GnRsU3Xs.kzuJIuP8B93PlWmbA98xCK817JvpeWDLLa',//bcrypt("12345678"),
                        'status' => $data['status'] ?? User::STATUS_ACTIVATED,
                        'created_at' => now()
                    ]);
                } else {
                    array_push($saveData, $user->fill([
                        'name' => $data['name'],
                        'status' => $data['status']
                    ]));
                }
            }
            $result = $this->batchInsertOrUpdate($saveData, "wz_users", ["name", "email", "password", "status", "created_at", "updated_at"]);

            return new JsonResponse([
                "message" => "Success",
                "data" => $result ?? []
            ]);
        }
        return new JsonResponse([
            "message" => "Fail",
            "data" => $ret
        ]);
    }

    /**
     * 批量插入或更新表中数据
     *
     * @param array $data 要插入的数据，元素中的key为表中的column，value为对应的值
     * @param string $table 要插入的表
     * @param array $columns 要更新的的表的字段
     * @return array
     */
    public static function batchInsertOrUpdate(array $data, $table = '', $columns = []): array
    {

        if (empty($data)) {//如果传入数据为空 则直接返回
            return [
                'insertNum' => 0,
                'updateNum' => 0
            ];
        }

        //拼装sql
        $sql = "insert into " . $table . " (";
        foreach ($columns as $k => $column) {
            $sql .= $column . " ,";
        }
        $sql = trim($sql, ',');
        $sql .= " ) values ";

        foreach ($data as $k => $v) {
            $sql .= "(";
            foreach ($columns as $kk => $column) {
                if ('updated_at' == $column) { //如果库中存在，create_at字段会被更新
                    $sql .= " '" . date('Y-m-d H:i:s') . "' ,";
                } else {
                    $val = ''; //插入数据中缺少$colums中的字段时的默认值
                    if (isset($v[$column])) {
                        $val = $v[$column];
                        $val = addslashes($val);  //在预定义的字符前添加反斜杠的字符串。
                    }
                    $sql .= " '" . $val . "' ,";
                }
            }
            $sql = trim($sql, ',');
            $sql .= " ) ,";
        }
        $sql = trim($sql, ',');
        $sql .= "on duplicate key update ";
        foreach ($columns as $k => $column) {
            $sql .= $column . " = values (" . $column . ") ,";
        }
        $sql = trim($sql, ',');
        $sql .= ';';

        $columnsNum = count($data);
        $retNum = DB::update(DB::raw($sql));
        $updateNum = $retNum - $columnsNum;
        $insertNum = $columnsNum - $updateNum;
        return [
            'insertNum' => $insertNum,
            'updateNum' => $updateNum
        ];
    }
}