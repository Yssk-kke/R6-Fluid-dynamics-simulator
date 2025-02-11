<?php


namespace App\Services;

use App\Commons\Constants;
use App\Commons\Message;
use App\Models\Db\Policy;
use App\Models\Db\Region;
use App\Models\Db\SimulationModel;
use App\Models\Db\SimulationModelPolicy;
use App\Models\Db\SimulationModelReferenceAuthority;
use App\Models\Db\SolarAbsorptivity;
use App\Models\Db\Solver;
use App\Models\Db\StlModel;
use App\Models\Db\Visualization;
use App\Utils\ArrayUtil;
use App\Utils\DatetimeUtil;
use App\Utils\FileUtil;
use Exception;
use Faker\Core\Uuid;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * シミュレーションモデルサービス
 */
class SimulationModelService extends BaseService
{

    /**
     *
     * シミュレーションモデル一覧を取得
     *
     * @param string $login_user_id ログインユーザ
     * @return \App\Models\DB\SimulationModel
     */
    public static function getSimulationModelList($login_user_id)
    {
            $simulationModelList = SimulationModel::where("registered_user_id", $login_user_id)
            ->orWhere("preset_flag", true) // 「シミュレーションモデルテーブル]のプリセットフラグが有効である。
            ->orWhereHas('simulation_model_reference_authorities',function ($query) use ($login_user_id) {
                    $query->where('user_id', $login_user_id);
            })
            ->orderBy('last_update_datetime', 'desc') // 最終更新日時降順
            ->take(Constants::SELECT_LIMIT) // 最大表示件数
            ->get();
        return $simulationModelList;
    }

    /**
     *
     * シミュレーションモデルIDでレコード取得
     *
     * @param Uuid $id シミュレーションモデルID
     *
     * @return\App\Models\Db\SimulationModel
     */
    public static function getSimulationModelById($id)
    {
        $simulationModel = SimulationModel::find($id);
        return $simulationModel;
    }

    /**
     * シミュレーションモデルの新規作成
     * @param string $identification_name 識別名
     * @param Uuid $city_model_id 都市モデルID
     * @param Region $region 解析対象地域
     * @param string $registeredUserId 登録ユーザID
     *
     * @return array 規追加結果(log含む)
     */
    public static function addNewSimulation($identification_name, $city_model_id, $region, $registeredUserId)
    {

        $result = true;
        $logInfos = [];
        $logInfo = "";

        $simulationModel = new SimulationModel();
        $simulationModel->identification_name = $identification_name;
        $simulationModel->city_model_id = $city_model_id;
        $simulationModel->region_id = $region->region_id;
        $simulationModel->registered_user_id = $registeredUserId;
        $simulationModel->last_update_datetime = DatetimeUtil::getNOW();
        $simulationModel->preset_flag = false;
        $simulationModel->temperature = 26.85;
        $simulationModel->wind_speed = 0;
        $simulationModel->wind_direction = 1;
        $simulationModel->solar_altitude_date = DatetimeUtil::getNOW(DatetimeUtil::DATE_FORMAT);
        $simulationModel->solar_altitude_time = 13;
        $simulationModel->south_latitude = $region->south_latitude;
        $simulationModel->north_latitude = $region->north_latitude;
        $simulationModel->west_longitude = $region->west_longitude;
        $simulationModel->east_longitude = $region->east_longitude;
        $simulationModel->ground_altitude = $region->ground_altitude;
        $simulationModel->sky_altitude = $region->sky_altitude;
        $simulationModel->solver_id = Solver::getByPresetFlag() ? Solver::getByPresetFlag()->solver_id : "";
        $simulationModel->mesh_level = 1;
        $simulationModel->run_status = Constants::RUN_STATUS_CODE_NONE;

        // シミュレーションモデルテーブルにレコードを新規追加
        if ($simulationModel->save()) {
            $logInfo = "[simulation_model] [insert] [ identification_name: {$identification_name}, city_model_id: {$city_model_id}, region_id: {$region->region_id}, registered_user_id: {$registeredUserId}]";
            array_push($logInfos, $logInfo);

            // シミュレーションモデル熱効率テーブルにレコードを新規追加
            if (self::addNewSolarAbsorptivity($simulationModel->simulation_model_id, $region->region_id)) {
                $logInfo = "[solar_absorptivity] [insert] [simulation_model_id: {$simulationModel->simulation_model_id}]";
                array_push($logInfos, $logInfo);
            } else {
                $result = false;
            }
        } else {
            $result = false;
        }
        return ["result" => $result, "log_infos" => $logInfos, 'simulation_model' => $simulationModel];
    }

    /**
     * シミュレーションモデル更新のバリエーション
     *   E9, E21, E23, E24, E25, E19, E20
     * @param Request $request リクエスト
     * @param Uuid $id シミュレーションモデルID
     *
     * @return array エラー
     */
    public static function isUpdate(Request $request, $id)
    {
        $errorMessage = [];

        $simulationModel = self::getSimulationModelById($id);

        if (!$request->identification_name) {
            $errorMessage = ["type" => "E", "code" => "E9", "msg" => Message::$E9];
        } else {
            // == 仕様変更により、画面上で解析条件入力欄を隠しにするため、E21, E23, E24のチェック処理を無効にします。==
            // // 南,西
            // $south_west = StringUtil::stringToArray($request->south_west);
            // // 北,東
            // $north_east = StringUtil::stringToArray($request->north_east);
            // if (!$south_west || !$north_east) {
            //     $errorMessage = ["type" => "E", "code" => "E21", "msg" => Message::$E21];
            // } else {
            //     // 南
            //     $south_latitude = number_format($south_west[0], 15);
            //     // 北
            //     $north_latitude = number_format($north_east[0], 15);
            //     // 西
            //     $west_longitude = number_format($south_west[1], 15);
            //     // 東
            //     $east_longitude = number_format($north_east[1], 15);

            //     if (!($simulationModel->region->south_latitude <= $south_latitude && $south_latitude < $north_latitude  && $north_latitude <= $simulationModel->region->north_latitude)) {
            //         $errorMessage = ["type" => "E", "code" => "E23", "msg" => sprintf(Message::$E23, $simulationModel->region->south_latitude . "", $simulationModel->region->north_latitude . "")];
            //     } else if (!($simulationModel->region->west_longitude <= $west_longitude && $west_longitude < $east_longitude && $east_longitude <= $simulationModel->region->east_longitude)) {
            //         $errorMessage = ["type" => "E", "code" => "E24", "msg" => sprintf(Message::$E24, $simulationModel->region->west_longitude . "", $simulationModel->region->east_longitude . "")];
            //     } else if (!($simulationModel->region->ground_altitude <= floatval($request->ground_altitude) && floatval($request->ground_altitude) < floatval($request->sky_altitude) && floatval($request->sky_altitude) <= $simulationModel->region->sky_altitude)) {
            //         $errorMessage = ["type" => "E", "code" => "E25", "msg" => sprintf(Message::$E25, $simulationModel->region->ground_altitude . "", $simulationModel->region->sky_altitude . "")];
            //     } else {

                    if (!($simulationModel->region->ground_altitude <= floatval($request->ground_altitude) && floatval($request->ground_altitude) < floatval($request->sky_altitude) && floatval($request->sky_altitude) <= $simulationModel->region->sky_altitude)) {
                        $errorMessage = ["type" => "E", "code" => "E25", "msg" => sprintf(Message::$E25, $simulationModel->region->ground_altitude . "", $simulationModel->region->sky_altitude . "")];
                    } else {

                        // === E19のチェック ====
                        $isNumber = true;
                        $item = "";
                        if(!is_numeric($request->temperature)) {
                            $isNumber = false;
                            $item = "外気温";
                        } else if (!is_numeric($request->wind_speed)) {
                            $isNumber = false;
                            $item = "風速";
                        } else if (!is_numeric($request->humidity)) {
                            $isNumber = false;
                            $item = "湿度";
                        }
                        // === E19のチェック //====
                        if (!$isNumber) {
                            $errorMessage = ["type" => "E", "code" => "E19", "msg" => sprintf(Message::$E19, $item)];
                        } else {
                            if (!(0 <= intval($request->solar_altitude_time) && intval($request->solar_altitude_time) <= 23)) {
                                $errorMessage = ["type" => "E", "code" => "E20", "msg" => Message::$E20];
                            } else {
                                // 「保存に続けてシミュレーションを開始する」をチェックした場合では、実行ステータスが「1 開始処理中」か「5 中止処理中」であれば、E5エラーを表示する。
                                if ($request->isStart && ($simulationModel->run_status == Constants::RUN_STATUS_CODE_START_PROCESSING || $simulationModel->run_status == Constants::RUN_STATUS_CODE_CANCEL_PROCESSING)) {
                                    $errorMessage = ["type" => "E", "code" => "E5", "msg" => sprintf(Message::$E5, Constants::RUN_STATUS_NONE, $simulationModel->identification_name)];
                                }
                            }
                        }
                    }
            // }
            // == 仕様変更により、画面上で解析条件入力欄を隠しにするため、E21, E23, E24のチェック処理を無効にします。//==
        }
        return $errorMessage;
    }

    /**
     * 編集画面よりシミュレーションモデルを更新する。
     * @param Request $request リクエスト
     * @param Uuid $id シミュレーションモデルID
     *
     * @return array 新規追加結果(log含む)
     */
    public static function updateSimulation(Request $request, $id)
    {
        $result = true;
        $logInfos = [];
        $logInfo = "";

        // シミュレーションモデルにレコード更新
        $simulationModel = self::getSimulationModelById($id);
        if ($simulationModel) {

            $simulationModel->identification_name = $request->identification_name; // 識別名
            $simulationModel->last_update_datetime = DatetimeUtil::getNOW(); // 最終更新日時
            $simulationModel->temperature = $request->temperature; // 外気温
            $simulationModel->wind_speed = $request->wind_speed; // 風速
            $simulationModel->wind_direction = $request->wind_direction; // 風向き
            $simulationModel->humidity = $request->humidity; // 湿度
            $simulationModel->solar_altitude_date = $request->solar_altitude_date; // 日付
            $simulationModel->solar_altitude_time = $request->solar_altitude_time; // 時間帯

            // 淡赤色の長方形の下辺がSM13南端緯度、上辺がSM14北端緯度、左辺がSM15西端経度、右辺がSM16東端経度とする。
            $simulationModel->south_latitude = $request->south_latitude; // 全域または、狭域指定で設定した南端緯度
            $simulationModel->north_latitude = $request->north_latitude; // 全域または、狭域指定で設定した北端緯度
            $simulationModel->west_longitude = $request->west_longitude; // 全域または、狭域指定で設定した西端経度
            $simulationModel->east_longitude = $request->east_longitude; // 全域または、狭域指定で設定した東端経度

            $simulationModel->ground_altitude = $request->ground_altitude; //   地面高度
            $simulationModel->sky_altitude = $request->sky_altitude; // 上空高度

            $simulationModel->solver_id = $request->solver_id; // ソルバID
            $simulationModel->mesh_level = $request->mesh_level; // メッシュ粒度

            // シミュレーションモデルテーブルにレコードを追加
            if ($simulationModel->save()) {
                $logInfo = "[simulation_model] [update] [simulation_model_id: {$id}]";
                array_push($logInfos, $logInfo);

                // シミュレーションモデル実施施策にレコード更新
                $smPolicies = $request->simulationModelPolicy;
                if (self::updateSimulationModelPolicy($simulationModel->simulation_model_id, $smPolicies)) {
                    $logInfo = "[simulation_model_policy] [update] [ simulation_model_id: {$simulationModel->simulation_model_id}]";
                    array_push($logInfos, $logInfo);
                } else {
                    $result = false;
                }
            } else {
                $result = false;
            }

            // シミュレーション開始するかどうか
            $isStart = $request->isStart;
            if ($isStart) {
                $startResult = self::startSimulation($simulationModel->simulation_model_id);
                if ($startResult['result']) {
                    $logInfos = array_merge($logInfos, $startResult['log_infos']);
                } else {
                    $result = false;
                }
            }
        } else {
            $result = false;
        }

        return ["result" => $result, "log_infos" => $logInfos];
    }

    /**
     *
     * シミュレーションモデルの複製
     *
     * @param string $loginUserId ログイン中のユーザ
     * @param Uuid $srcId コピー元のシミュレーションモデルID
     *
     * @return array 複製結果(log含む)
     */
    public static function copySimulationModel($loginUserId, $srcId)
    {
        $result = true;
        $logInfos = [];
        $logInfo = "";

        // == シミュレーションモデルのレコードを複製 ==
        // コピー元のシミュレーションモデルのレコードを取得
        $srcSimulationModel = self::getSimulationModelById($srcId);
        $simulationModel = new SimulationModel();
        foreach ($srcSimulationModel->getFillable() as $attribute) {
            if ($attribute == 'registered_user_id') {
                // 複製したレコードの登録者をログインユーザとする
                $simulationModel->{$attribute} = $loginUserId;
            } else if ($attribute != 'simulation_model_id') {
                $simulationModel->{$attribute} = $srcSimulationModel->{$attribute};
            }
        }
        // SM7プリセットフラグを0(無効)、SM21実行ステータスを0（未）、SM22実行ステータス詳細を未入力、SM26一般公開フラグを0(無効)とする。
        $simulationModel->preset_flag = false;
        $simulationModel->run_status = Constants::RUN_STATUS_CODE_NONE;
        $simulationModel->run_status_details = null;
        $simulationModel->disclosure_flag = false;
        // == シミュレーションモデルのレコードを複製 //==

        if ($simulationModel->save()) {
            $logInfo = "[simulation_model] [insert] [copy from simulation: {$srcId}]";
            array_push($logInfos, $logInfo);

            // == シミュレーションモデル熱効率のレコードを複製 ==
            $result = self::copySolarAbsorptivity($srcId, $simulationModel->simulation_model_id);
            if ($result) {
                $logInfo = "[solar_absorptivity] [insert] [copy from simulation: {$srcId}]";
                array_push($logInfos, $logInfo);

                // == シミュレーションモデル実施施策のレコードを複製 ==
                $result = self::copySimulationModelPolicy($srcId, $simulationModel->simulation_model_id);
                if ($result) {
                    $logInfo = "[simulation_model_policy] [insert] [copy from simulation: {$srcId}]";
                    array_push($logInfos, $logInfo);
                }
            }
        } else {
            $result = false;
        }

        return ["result" => $result, "log_infos" => $logInfos];
    }

    /**
     *
     * シミュレーションモデル削除
     * @param Uuid $id シミュレーションモデルID
     * @return array 削除結果(log含む)
     */
    public static function deleteSimulationModelById($id)
    {
        $result = true;
        $logInfos = [];
        $logInfo = "";

        // シミュレーションモデル熱効率から削除
        $solarAbsorptivitys = SolarAbsorptivity::where(['simulation_model_id' => $id])->count();
        if ($solarAbsorptivitys > 0) {
            if (SolarAbsorptivity::where(['simulation_model_id' => $id])->delete() > 0) {
                $logInfo = "[solar_absorptivity] [delete] [simulation_model_id: {$id}]";
                array_push($logInfos, $logInfo);
            } else {
                $result =  false;
            }
        }

        // シミュレーションモデル実施施策から削除
        $simulationModelPolicys = self::getSimulationModelById($id)->simulation_model_policies()->count();
        if ($simulationModelPolicys > 0) {
            if ($result && (SimulationModelPolicy::where(['simulation_model_id' => $id])->delete() > 0)) {
                $logInfo = "[simulation_model_policy] [delete] [simulation_model_id: {$id}]";
                array_push($logInfos, $logInfo);
            } else {
                $result =  false;
            }
        }

        // シミュレーションモデル参照権限テーブルから削除
        $simulationModelReferenceAuthoritys = SimulationModelReferenceAuthority::where(['simulation_model_id' => $id])->count();
        if ($simulationModelReferenceAuthoritys > 0 ) {
            if ($result && (SimulationModelReferenceAuthority::where(['simulation_model_id' => $id])->delete() > 0)) {
                $logInfo = "[simulation_model_reference_authority] [delete] [simulation_model_id: {$id}]";
                array_push($logInfos, $logInfo);
            } else {
                $result =  false;
            }
        }

        // シミュレーションモデルから削除
        if ($result && (SimulationModel::destroy($id) > 0)) {
            $logInfo = "[simulation_model] [delete] [simulation_model_id: {$id}]";
            array_push($logInfos, $logInfo);
        } else {
            $result =  false;
        }

        return ["result" => $result, "log_infos" => $logInfos];
    }

    /**
     * シミュレーションモデルのレコード更新
     * @param Uuid $id シミュレーションモデルID
     * @param string $attribute 更新対象要素
     * @param mixed $val 更新値
     *
     * @return bool
     *  更新に成功した場合、true
     *  更新に失敗した場合、false
     */
    public static function updateSimulationById($id, $attribute, $val)
    {
        $simulationModel = self::getSimulationModelById($id);

        if ($simulationModel && (array_search($attribute, $simulationModel->getFillable()) >= 0)) {
            $simulationModel->{$attribute} = $val;
            return $simulationModel->save();
        } else {
            return false;
        }
    }

    /**
     * シミュレーション開始処理
     * @param Uuid $id シミュレーションモデルID
     * @return array シミュレーション開始処理結果とログ情報
     */
    public static function startSimulation($id)
    {
        $result = true;

        $logInfos = [];

        // シミュレーションモデルテーブルの実行ステータス「1 開始処理中」更新
        if (self::updateSimulationById($id, 'run_status', Constants::RUN_STATUS_CODE_START_PROCESSING)) {
            array_push($logInfos, sprintf("[simulation_model] [update] [simulation_model_id: {$id}, run_status: %s])", Constants::RUN_STATUS_CODE_START_PROCESSING));
        } else {
            $result = false;
        }

        // シミュレーションモデルテーブルの実行ステータス詳細「ユーザ操作により処理を開始しました。」更新
        if (self::updateSimulationById($id, 'run_status_details', "ユーザ操作により処理を開始しました。")) {
            array_push($logInfos, "[simulation_model] [update] [simulation_model_id: {$id}, run_status_details: ユーザ操作により処理を開始しました。]");
        } else {
            $result = false;
        }
        // シミュレーションモデルテーブルの最終シミュレーション開始日時「現在の日時」更新
        if (self::updateSimulationById($id, 'last_sim_start_datetime', DatetimeUtil::getNOW())) {
            array_push($logInfos, sprintf("[simulation_model] [update] [simulation_model_id: {$id}, last_sim_start_datetime: %s]", DatetimeUtil::getNOW()));
        } else {
            $result = false;
        }

        // シミュレーション関連する可視化ファイルを全て削除
        if (self::getSimulationModelById($id)->visualizations()->count() > 0) {
            if (self::getSimulationModelById($id)->visualizations()->delete() > 0) {
                array_push($logInfos, "[visualization] [delete] [simulation_model_id: {$id}]");
            } else {
                $result = false;
            }
        }
        return ["result" => $result, "log_infos" => $logInfos];
    }

    /**
     * シミュレーション中止処理
     * @param Uuid $id シミュレーションモデルID
     * @return array シミュレーション中止処理結果とログ情報
     */
    public static function stopSimulation($id)
    {
        $result = true;

        $logInfos = [];

        // シミュレーションモデルテーブルの実行ステータス「5 中止処理中」更新
        if (self::updateSimulationById($id, 'run_status', Constants::RUN_STATUS_CODE_CANCEL_PROCESSING)) {
            array_push($logInfos, sprintf("[simulation_model] [update] [simulation_model_id: {$id}, run_status: %s])", Constants::RUN_STATUS_CODE_CANCEL_PROCESSING));
        } else {
            $result = false;
        }

        // シミュレーションモデルテーブルの実行ステータス詳細「ユーザ操作により処理を中止しました。」更新
        if (self::updateSimulationById($id, 'run_status_details', "ユーザ操作により処理を中止しました。")) {
            array_push($logInfos, "[simulation_model] [update] [simulation_model_id: {$id}, run_status_details: ユーザ操作により処理を中止しました。]");
        } else {
            $result = false;
        }
        return ["result" => $result, "log_infos" => $logInfos];
    }

    /**
     * 可視化ファイルを取得
     * @param Uuid $simulation_model_id シミュレーションモデルID
     * @param int $visualization_type 可視化種別:1(風況)、2(温度)、3(暑さ指数)
     * @param int $height_id 相対高さID
     * @param int $legend_type 凡例種別: 1(変動)、2(固定)
     *
     * @return \App\Models\Db\Visualization 可視化ファイル
     */
    public static function getVisualization($simulation_model_id, $visualization_type, $height_id, $legend_type = Constants::LEGENF_TYPE_FLUCTUATION)
    {
        $visualization =  Visualization::where([
            "simulation_model_id" => $simulation_model_id,
            "visualization_type" => $visualization_type,
            "legend_type" => $legend_type]);

        // 可視化種別は「3(暑さ指数)」の場合では、高さを無視にする。
        if ($visualization_type != Constants::VISUALIZATION_TYPE_HEAT_INDEX) {
            $visualization->where('height_id', $height_id);
        }
        return $visualization->first();
    }

    /**
     * シミュレーションモデル熱効率テーブルにレコード新規追加
     * @param Uuid $simulation_model_id シミュレーションモデルID
     * @param Uuid $region_id 解析対象地域ID
     *
     * @return bool
     *  新規追加に成功した場合、true
     *  新規追加に失敗した場合、false
     */
    public static function addNewSolarAbsorptivity($simulation_model_id, $region_id)
    {
        $stlModels = StlModel::where('region_id', $region_id)->orderBy('stl_type_id', 'asc')->get();

        $result = true;
        foreach ($stlModels as $stlModel) {
            $solarAbsorptivity = new SolarAbsorptivity();
            $solarAbsorptivity->simulation_model_id = $simulation_model_id;
            $solarAbsorptivity->stl_type_id = $stlModel->stl_type_id;
            // 日射吸収率の初期値はSTLファイルの日射吸収率とする。
            $solarAbsorptivity->solar_absorptivity = $stlModel->solar_absorptivity;
            // 排熱量の初期値はSTLファイルの排熱量とする。
            $solarAbsorptivity->heat_removal = $stlModel->heat_removal;

            if (!$solarAbsorptivity->save()) {
                $result = false;
                break;
            }
        }
        return $result;
    }

    /**
     * シミュレーションモデル熱効率のレコードを複製
     * @param Uuid $src_simulation_model_id コピー元のシミュレーションモデル
     * @param Uuid $des_simulation_model_id コピー先のシミュレーションモデル
     *
     * @return bool
     *  複製に成功した場合、true
     *  複製に失敗した場合、false
     */
    public static function copySolarAbsorptivity($src_simulation_model_id, $des_simulation_model_id)
    {
        $result = true;

        // コピー元のシミュレーションモデル熱効率のレコードを取得
        $solarAbsorptivitys = SolarAbsorptivity::getBySimulationId($src_simulation_model_id);
        foreach ($solarAbsorptivitys as $srcSolarAbsorptivity) {
            $newSolarAbsorptivity = new SolarAbsorptivity();
            $newSolarAbsorptivity->simulation_model_id = $des_simulation_model_id;
            $newSolarAbsorptivity->stl_type_id = $srcSolarAbsorptivity->stl_type_id;
            $newSolarAbsorptivity->solar_absorptivity = $srcSolarAbsorptivity->solar_absorptivity;
            $newSolarAbsorptivity->heat_removal = $srcSolarAbsorptivity->heat_removal;
            if (!$newSolarAbsorptivity->save()) {
                $result = false;
                break;
            }
        }
        return $result;
    }

    /**
     * シミュレーションモデル実施施策のレコードを複製
     * @param Uuid $src_simulation_model_id コピー元のシミュレーションモデル
     * @param Uuid $des_simulation_model_id コピー先のシミュレーションモデル
     *
     * @return bool
     *  複製に成功した場合、true
     *  複製に失敗した場合、false
     */
    public static function copySimulationModelPolicy($src_simulation_model_id, $des_simulation_model_id)
    {
        $result = true;

        // コピー元のシミュレーションモデル実施施策のレコードを取得
        $simulationModelPolicys = self::getSimulationModelById($src_simulation_model_id)->simulation_model_policies()->get();
        foreach ($simulationModelPolicys as $srcSimulationModelPolicy) {
            $newSimulationModelPolicy = new SimulationModelPolicy();
            $newSimulationModelPolicy->simulation_model_id = $des_simulation_model_id;
            $newSimulationModelPolicy->stl_type_id = $srcSimulationModelPolicy->stl_type_id;
            $newSimulationModelPolicy->policy_id = $srcSimulationModelPolicy->policy_id;
            if (!$newSimulationModelPolicy->save()) {
                $result = false;
                break;
            }
        }
        return $result;
    }

    /**
     * 熱対策施策テーブルの各レコードを取得
     * @return Collection Policy
     */
    public static function getAllPolicy()
    {
        // 施策IDの昇順でレコードを取得する。
        return Policy::all()->sortBy('policy_id');
    }


    /**
     * 実施施策一覧に実施施策を新規追加
     * @param Request $request リクエスト
     * @param SimulationModel $simulation_model 編集対象のシミュレーション
     * @param integer $stl_type_id 追加しようとする対象
     * @param integer $policy_id 追加しようとする施設
     * @param string $sm_policy_session_key セッションキー
     *
     * @return Collection SimulationModelPolicy シミュレーションモデル実施施策
     */
    public static function addNewSmPolicy($request, $simulation_model, $stl_type_id, $policy_id, $sm_policy_session_key)
    {
        // 現状のシミュレーションモデル実施施策の各レコードを取得
        $smPoliciesSession = $request->session()->get($sm_policy_session_key);
        $smPolicies = $smPoliciesSession ? $smPoliciesSession : $simulation_model->simulation_model_policies()->get();

        // 新規追加しようとするデータが既に存在しているかチェック
        $newSmPolicyIsExist = $smPolicies->filter(function($item) use ($stl_type_id, $policy_id) {
            return $item->stl_type_id == intval($stl_type_id) && $item->policy_id == intval($policy_id);
        })->count() > 0;

        // 新規追加しようとするデータが存在していない場合、実施施策一覧に行を追加する。
        if (!$newSmPolicyIsExist) {
            $smPolicy = new SimulationModelPolicy(['simulation_model_id' => $simulation_model->simulation_model_id, 'stl_type_id' => $stl_type_id, 'policy_id' => $policy_id]);
            $smPolicies->put($smPolicies->count(), $smPolicy);
            $request->session()->put($sm_policy_session_key, $smPolicies);
        }

        return $smPolicies;
    }

    /**
     * 実施施策一覧より選択した行を削除
     * @param Request $request リクエスト
     * @param SimulationModel $simulation_model 編集対象のシミュレーション
     * @param integer $stl_type_id 削除しようとする対象
     * @param integer $policy_id 削除しようとする施設
     * @param string $sm_policy_session_key セッションキー
     *
     * @return Collection SimulationModelPolicy シミュレーションモデル実施施策
     */
    public static function deleteSmPolicy($request, $simulation_model, $stl_type_id, $policy_id, $sm_policy_session_key)
    {
        // 現状のシミュレーションモデル実施施策の各レコードを取得
        $smPoliciesSession = $request->session()->get($sm_policy_session_key);
        $smPolicies = $smPoliciesSession ? $smPoliciesSession : $simulation_model->simulation_model_policies()->get();

        // 選択中の「施策」と「対象」を実施施策一覧より削除
        $smPolicies = $smPolicies->filter(function($item) use ($stl_type_id, $policy_id) {
            return !(($item->stl_type_id == intval($stl_type_id)) && ($item->policy_id == intval($policy_id)));
        });

        $request->session()->put($sm_policy_session_key, $smPolicies);

        return $smPolicies;
    }

    /**
     * シミュレーションモデル実施施策テーブルにレコードを更新する。
     * @param Uuid $simulation_model_id シミュレーションモデルID
     * @param array $new_simulation_model_policies 更新対象レコード
     *
     * @return bool
     *  更新に成功した場合、true
     *  更新に失敗した場合、false
     */
    public static function updateSimulationModelPolicy($simulation_model_id, $new_simulation_model_policies)
    {
        $result = true;

        // 編集対象シミュレーションモデルに関する全てレコードを削除
        $oldSimulationModelPolicies = SimulationModelPolicy::where('simulation_model_id', $simulation_model_id)->count();
        if ($oldSimulationModelPolicies > 0) {
            if (!(SimulationModelPolicy::where('simulation_model_id', $simulation_model_id)->delete() > 0)) {
                $result = false;
            }
        }

        // レコード登録し直す
        if ($result && $new_simulation_model_policies) {
            foreach ($new_simulation_model_policies as $smPolicy) {
                $simulationModelPolicy = new SimulationModelPolicy($smPolicy);
                // シミュレーション結果閲覧画面でシミュレーション再作成を行う際でも使うため、レコード挿入前にシミュレーションモデルIDを再設定する。
                $simulationModelPolicy->simulation_model_id = $simulation_model_id;
                if (!$simulationModelPolicy->save()) {
                    $result = false;
                    break;
                }
            }
        }
        return $result;
    }

    /**
     * シミュレーションモデルの建物データ(czmlファイルパス)を取得する。
     *  nullのczmlファイルは対象外です。
     * @param mixed $simulationModel シミュレーションモデル
     *
     * @return array
     */
    public static function getCzmlFileWithoutNull($simulationModel)
    {
        // 建物などのSTLファイル
        $stlFiles = $simulationModel->region->stl_models()->get();
        // 3D地図表示のため、特定の解析対象地域に紐づいていたczmlファイルを取得する。
        // 「解析対象地域ID」に紐づいた複数の「STLファイル種別ID」ですが、すべてczmlファイル表示対象となる。
        $czmlFiles = $stlFiles->filter(function ($stl_model) {
            // 表示対象はSTLファイル種別テーブル.地表面フラグ=0(false) （すなわち建物）のSTLファイルよりレコードを取得します
            if (!$stl_model->stl_type->ground_flag) {
                // 念のため（こちらで閲覧出来る状態となるため、czml_fileがないケースはあり得ない。）
                if ($stl_model->czml_file) {
                    return $stl_model;
                }
            }
        })
        ->map(function ($stl_model) {
            return FileUtil::referenceStorageFile($stl_model->czml_file);
        })->values()->toArray();

        return $czmlFiles;
    }

    /**
     * シミュレーションモデル再作成に異常があったかどうかバリエーション
     *  E9, E19, E20 のエラーチェック
     * @param Request $request リクエスト
     *
     * @return array エラーメッセージ
     */
    public static function recreateIsOk(Request $request)
    {
        $errorMessage = [];

        if (!$request->identification_name) {
            // === E9のチェック //====
            $errorMessage = ["type" => "E", "code" => "E9", "msg" => Message::$E9];
        } else {
            // === E19のチェック ====
            $isNumber = true;
            $item = "";
            if (!is_numeric($request->temperature)) {
                $isNumber = false;
                $item = "外気温";
            } else if (!is_numeric($request->wind_speed)) {
                $isNumber = false;
                $item = "風速";
            } else if (!is_numeric($request->humidity)) {
                $isNumber = false;
                $item = "湿度";
            }
            // === E19のチェック //====
            if (!$isNumber) {
                $errorMessage = ["type" => "E", "code" => "E19", "msg" => sprintf(Message::$E19, $item)];
            } else {
                // === E20のチェック //====
                if (!(0 <= intval($request->solar_altitude_time) && intval($request->solar_altitude_time) <= 23)) {
                    $errorMessage = ["type" => "E", "code" => "E20", "msg" => Message::$E20];
                }
            }
        }

        return $errorMessage;
    }


    /**
     * シミュレーションモデルの再作成を行う。
     * @param Request $request リクエスト
     * @param string $loginUserId ログイン中のユーザ
     * @param Uuid $simulation_model_id_src 作成元のシミュレーションモデルID
     *
     * @return array シミュレーションモデルの再作成結果(log含む)
     */
    public static function recreateSimulation(Request $request, $loginUserId, $simulation_model_id_src)
    {
        $result = true;
        $logInfos = [];
        $logInfo = "";

        // == シミュレーションモデルのレコードを挿入 ==
        // 作成元のシミュレーションモデルのレコードを取得
        $srcSimulationModel = self::getSimulationModelById($simulation_model_id_src);

        $simulationModel = new SimulationModel();
        foreach ($srcSimulationModel->getFillable() as $attribute) {
            if ($attribute == 'registered_user_id') {
                // 複製したレコードの登録者をログインユーザとする
                $simulationModel->{$attribute} = $loginUserId;
            } else if ($attribute != 'simulation_model_id') {
                $simulationModel->{$attribute} = $srcSimulationModel->{$attribute};
            }
        }

        // 識別名
        $simulationModel->identification_name = $request->identification_name;
        // 最終更新日時を現在日時にする。
        $simulationModel->last_update_datetime = DatetimeUtil::getNOW(DatetimeUtil::DATE_TIME_FORMAT);
        // 外気温
        $simulationModel->temperature = $request->temperature;
        // 風速
        $simulationModel->wind_speed = $request->wind_speed;
        // 風向き
        $simulationModel->wind_direction = $request->wind_direction;
        // 湿度
        $simulationModel->humidity = $request->humidity;
        // 日付
        $simulationModel->solar_altitude_date = $request->solar_altitude_date;
        // 時間帯
        $simulationModel->solar_altitude_time = $request->solar_altitude_time;

        // SM7プリセットフラグを0(無効)、SM21実行ステータスを0（未）、SM22実行ステータス詳細を未入力、SM26一般公開フラグを0(無効)とする。
        $simulationModel->preset_flag = false;
        $simulationModel->run_status = Constants::RUN_STATUS_CODE_NONE;
        $simulationModel->run_status_details = null;
        $simulationModel->disclosure_flag = false;
        // == シミュレーションモデルのレコードを挿入 //==

        if ($simulationModel->save()) {
            $logInfo = "[simulation_model] [insert] [ simulation_model_id: {$simulationModel->simulation_model_id}] [recreate from simulation: {$simulation_model_id_src}]";
            array_push($logInfos, $logInfo);

            // == シミュレーションモデル熱効率のレコードを挿入 ==
            $result = self::addNewSolarAbsorptivity($simulationModel->simulation_model_id, $simulationModel->region->region_id);
            if ($result) {
                $logInfo = "[solar_absorptivity] [insert] [ simulation_model_id: {$simulationModel->simulation_model_id}] [recreate from simulation: {$simulation_model_id_src}]";
                array_push($logInfos, $logInfo);

                // == シミュレーションモデル実施施策にレコードを挿入 ==
                $smPolicies = $request->simulationModelPolicy;
                if (self::updateSimulationModelPolicy($simulationModel->simulation_model_id, $smPolicies)) {
                    $logInfo = "[simulation_model_policy] [insert] [ simulation_model_id: {$simulationModel->simulation_model_id}] [recreate from simulation: {$simulation_model_id_src}]";
                    array_push($logInfos, $logInfo);
                } else {
                    $result = false;
                }
            }
        } else {
            $result = false;
        }

        // シミュレーション開始するかどうか
        $isStart = $request->isStart;
        if ($isStart) {
            $startResult = self::startSimulation($simulationModel->simulation_model_id);
            if ($startResult['result']) {
                $logInfos = array_merge($logInfos, $startResult['log_infos']);
            } else {
                $result = false;
            }
        }

        return ["result" => $result, "log_infos" => $logInfos];
    }

    /**
     * 風向表示設定ファイル(json)を読み込んで、プルダウン表示ようのデータを作成
     * @return array プルダウン表示ようのデータ
     */
    public static function createWindirectionDropdown()
    {
        $windDirections = [];
        $windirectionSettingFilePath = FileUtil::SETTING_FOLDER . "/" . FileUtil::WIND_DIRECTION_FILE;

        if (FileUtil::isExists($windirectionSettingFilePath)) {
            // 風向表示設定用のJSONファイルを読み込む
            $windDirectionJsonData = FileUtil::getStorageFile($windirectionSettingFilePath);
            if ($windDirectionJsonData) {
                $windDirections = json_decode($windDirectionJsonData, true);
                if ($windDirections) {
                    // 選択肢(display_order)の表示順番に基づいてプルダウンメニューを生成
                    $windDirections = ArrayUtil::sortArrayBykey($windDirections, 'display_order');
                } else {
                    throw new Exception("風向表示設定用のJSONファイルは正しく定義されていません。");
                }
            } else {
                throw new Exception("風向表示設定用のJSONファイルは空ファイルです。");
            }
        } else {
            throw new Exception("風向表示設定用のJSONファイルが存在しません。{$windirectionSettingFilePath}");
        }

        return $windDirections;
    }
}
