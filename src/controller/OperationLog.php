<?php
declare(strict_types=1);

namespace wise\operationlog\controller;

use app\common\controller\traits\CrudTrait;
use think\facade\View;
use wise\operationlog\model\OperationLogModel;

/**
 * 操作日志 — 后台管理（包内控制器）
 *
 * 由包内 RouteRegistrar 以 `/admin/operation_log/*` 落地，
 * 菜单见 wise\operationlog\menu\OperationLogMenuProvider
 * （挂在框架「日志管理」目录下）。
 *
 * 视图归属：控制器继承宿主 app\admin\controller\BaseController，
 * initialize() 中 ViewScope::activate('wise-operation-log')，使显式模板名
 * `admin/operation_log/index` 兜底到本包 view/ 目录；
 * 宿主 app/admin/view/** 放同名文件仍可覆盖（L1 优先）。
 *
 * 前端 ESM 模块随包发布：`../plugins/wise-operation-log/js/operation_log`
 * （源文件为本包 `public/js/operation_log.js`，由 `wise:vendor:publish` 复制到
 * 应用的 `public/statics/plugins/wise-operation-log/`）。
 */
class OperationLog extends \app\admin\controller\BaseController
{
    use CrudTrait;

    /** 视图 scope（视图驱动据此把相对模板名兜底到本包 view/ 目录） */
    protected const VIEW_SCOPE = 'wise-operation-log';

    protected function getModel(): \think\Model
    {
        return new OperationLogModel();
    }

    protected function initialize()
    {
        parent::initialize();

        try {
            if (class_exists(\app\common\view\ViewScope::class)) {
                \app\common\view\ViewScope::activate(self::VIEW_SCOPE);
            } else {
                // 视图驱动直接读取请求级载体，无 ViewScope 时等价生效
                $this->request->view_scope = self::VIEW_SCOPE;
            }
        } catch (\Throwable $e) {
            // 无视图 scope 机制时不影响渲染（显式模板名仍按宿主解析）
        }
    }

    /**
     * 首页
     * @return string
     */
    public function index()
    {
        View::assign('wsconfig', array_merge(
            (array) View::get('wsconfig'),
            ['js' => '../plugins/wise-operation-log/js/operation_log']
        ));

        return $this->fetch('admin/operation_log/index');
    }

    /**
     * 日志列表
     */
    public function getList()
    {
        $this->buildQueryParams();
        $operatorName = $this->request->param('operator_name');
        $content      = $this->request->param('content');
        $channel      = $this->request->param('channel');
        $level        = $this->request->param('level');
        $dateRange    = $this->request->param('date_range');

        $query = OperationLogModel::order('id', 'desc');

        // 转义 LIKE 通配符：参数绑定可防注入，但 % / _ 会被当作模式字符，
        // 例如 operator_name=% 会退化为全表匹配（与 LogManager::escapeLike 口径一致）
        $escapeLike = static fn(string $value): string => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);

        if ($operatorName) {
            $query->where('operator_name', 'like', '%' . $escapeLike((string) $operatorName) . '%');
        }

        if ($content) {
            $query->where('content', 'like', '%' . $escapeLike((string) $content) . '%');
        }

        if ($channel) {
            $query->where('channel', $channel);
        }

        if ($level) {
            $query->where('level', $level);
        }

        if ($dateRange) {
            // 限定 2 段，避免缺少分隔符时 list() 解构产生 Undefined array key 告警
            $range = explode(' - ', (string) $dateRange, 2);
            if (count($range) === 2 && $range[0] !== '' && $range[1] !== '') {
                $query->whereBetweenTime('create_time', $range[0], $range[1]);
            }
        }

        $list = $query->paginate(['list_rows' => $this->pagesize, 'page' => $this->page])->toArray();

        return $this->success('success', $this->formatPaginate($list));
    }

    /**
     * 操作日志预览页面
     * @return string
     */
    public function preview()
    {
        $id = $this->request->param('id/d', 0);

        // 获取操作日志信息
        $operationLog = OperationLogModel::findOrEmpty($id);
        if ($operationLog->isEmpty()) {
            return $this->error('操作日志不存在');
        }

        return $this->assign([
            'operationLog' => $operationLog,
        ])->fetch('admin/operation_log/preview');
    }

    /**
     * 数据分析仪表板
     */
    public function dashboard()
    {
        $analysisService = new \app\admin\service\LogAnalysisService();

        $data = [
            'login_stats'     => $analysisService->getLoginStats(7),
            'operation_stats' => $analysisService->getOperationStats(7),
            'api_performance' => $analysisService->getApiPerformance(7),
            'security_risks'  => $analysisService->securityRiskDetection(),
            'system_health'   => $analysisService->getSystemHealth(),
        ];

        return json([
            'code' => 0,
            'data' => $data,
            'msg'  => 'success',
        ]);
    }

    /**
     * 清理日志
     */
    public function clearLog()
    {
        $days = (int) $this->request->param('days/d', 30);

        // 下界校验：days<=0 时 clearDate 落在当前或未来时刻，等价于全表删除且不可恢复；
        // 空值（?days=）经 /d 过滤后同样为 0，必须拦截。
        if ($days <= 0 || $days > 36500) {
            return $this->error('保留天数必须为 1~36500 之间的正整数');
        }

        // 复用 LogManager 的守卫逻辑（含天数上限与异常兜底），避免与 cleanup() 行为分叉
        $deleted = \wise\operationlog\facade\OperationLog::cleanup($days);

        return $this->success("日志清理成功，删除 {$deleted} 条");
    }
}
