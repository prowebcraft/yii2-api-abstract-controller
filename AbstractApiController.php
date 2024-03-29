<?php
/**
 * Abstract Api Controller for handling API Requests
 * User: Andrey M.
 * Email: prowebcraft@gmail.com
 * Date: 17.03.2021 18:11
 */

namespace prowebcraft\yii2apicontroller;

use Yii as Yii;
use yii\base\InlineAction;
use yii\base\InvalidRouteException;
use yii\base\Module;
use yii\helpers\ArrayHelper;
use yii\helpers\Inflector;
use yii\web\Controller;
use yii\web\Response;

abstract class AbstractApiController extends Controller
{

    public $enableCsrfValidation = false;
    protected $apiRequestParams = null;

    /**
     * List of actions without processing validateRequest method
     * @var array
     */
    public $whiteListActions = [];

    /**
     * Log incoming request data
     * @var bool
     */
    protected bool $logRequest = true;

    /**
     * Log response
     * @var bool
     */
    protected bool $logResponse = true;

    /**
     * Category for logging
     * @var string
     */
    protected $logCategory = 'api';

    /** @var bool Allow CORS Requests */
    protected bool $allowCors = false;
    /** @var bool Allow CORS Requests in Dev environment */
    protected bool $allowCorsInDev = false;

    protected array $requestParams = [];

    /**
     * Request bootstrap & validation
     * @param InlineAction $action
     * @return mixed
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\web\BadRequestHttpException
     */
    public function beforeAction($action)
    {
        $path = \Yii::$app->getRequest()->getPathInfo();
        if ($this->logRequest) {
            $logParams = $this->getRequestParamsForLog();
            \Yii::info(sprintf('>> Api Request: %s; Params: %s',
                $path, $logParams), $this->logCategory);
        }

        $this->enableCsrfValidation = false;
        if (!(is_array($this->whiteListActions)
                && in_array($action->actionMethod, $this->whiteListActions)) && !$this->validateRequest()) {
            $this->throwError('Invalid Validation Data', 401);
        }

        $method = new \ReflectionMethod($this, $action->actionMethod);
        foreach ($method->getParameters() as $parameter) {
            $snakeCaseName = Inflector::camel2id($parameter->name, '_');
            $paramNames = array_unique([
                $snakeCaseName,
                $parameter->name
            ]);
            foreach ($paramNames as $paramName) {
                if (($val = $this->getParam($paramName,
                        $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null
                    )) !== null) {
                    $params[$parameter->name] = $val;
                    break;
                }
            }
            if ($val === null && !$parameter->isOptional()) {
                $this->throwError('Required Param ' . $snakeCaseName . ' is not set', 423);
            }
            $this->requestParams[$parameter->name] = $val;
        }

        return parent::beforeAction($action);
    }

    /**
     * Validate Request
     * @return bool
     */
    protected function validateRequest()
    {
        return true;
    }

    /**
     * Correct default response type in json
     * @param string $id
     * @return \yii\base\Action
     */
    public function createAction($id)
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;
        return parent::createAction($id);
    }

    /**
     * @inheritDoc
     */
    public function runAction($id, $params = [])
    {
        if ($this->allowCors || ($this->allowCorsInDev && YII_ENV === 'dev')) {
            if (YII_DEBUG && isset($_SERVER['HTTP_ORIGIN'])) {
                header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}", true);
                header('Access-Control-Allow-Credentials: true', true);
                header('Access-Control-Allow-Headers: Authorization,Accept,Origin,DNT,X-CustomHeader,' .
                    'Keep-Alive,User-Agent,X-Requested-With,If-Modified-Since,Cache-Control,Content-Type,' .
                    'Content-Range,Range,SPA,withcredentials,auth,sentry-trace,x-appic-session,x-appic-shop', true
                );
            } else {
                header('Access-Control-Allow-Origin: *', true);
                header('Access-Control-Allow-Headers: *', true);
            }
        }
        if (\Yii::$app->getRequest()->isOptions) {
            exit(); // this is preflight OPTIONS request
        }
        $this->requestParams = $params;
        $res = $this->wrap(function () use ($id) {
            $action = $this->createAction($id);
            if ($action === null) {
                throw new InvalidRouteException('Unable to resolve the request: ' . $this->getUniqueId() . '/' . $id);
            }
            $action = $this->createAction($id);
            if ($action === null) {
                throw new InvalidRouteException('Unable to resolve the request: ' . $this->getUniqueId() . '/' . $id);
            }

            Yii::debug('Route to run: ' . $action->getUniqueId(), __METHOD__);

            if (Yii::$app->requestedAction === null) {
                Yii::$app->requestedAction = $action;
            }

            $oldAction = $this->action;
            $this->action = $action;

            $modules = [];
            $runAction = true;

            // call beforeAction on modules
            foreach ($this->getModules() as $module) {
                if ($module->beforeAction($action)) {
                    array_unshift($modules, $module);
                } else {
                    $runAction = false;
                    break;
                }
            }

            $result = null;

            if ($runAction && $this->beforeAction($action)) {
                // run the action
                $result = $action->runWithParams($this->requestParams);

                $result = $this->afterAction($action, $result);

                // call afterAction on modules
                foreach ($modules as $module) {
                    /* @var $module Module */
                    $result = $module->afterAction($action, $result);
                }
            }

            if ($oldAction !== null) {
                $this->action = $oldAction;
            }

            return $result;
        });
        $this->finishAjaxAction($res);
    }

    /**
     * Collect params for logging (with passwords obfuscation)
     * @return string
     */
    protected function getRequestParamsForLog() {
        $params = $this->getParams();
        array_walk_recursive($params, function(&$item, $key) {
            if (strcasecmp($key, 'pass') == 0 || strcasecmp($key, 'password') == 0) {
                $item = '***';
            }

        });

        return json_encode($params, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    }

    /**
     * Get request body params from both POST and Raw Body sourses
     * @return array
     */
    protected function getParams()
    {
        if ($this->apiRequestParams === null) {
            $json = Yii::$app->getRequest()->getRawBody();
            $json = json_decode($json, true) ?: [];
            $this->apiRequestParams = array_merge($json, Yii::$app->getRequest()->post(), Yii::$app->getRequest()->get());
        }

        return $this->apiRequestParams;
    }

    /**
     * Get Request Params from $_POST or RAW JSON BODY
     * @param null|string $name
     * Param key
     * Returns all params if null
     * @param mixed $defaultValue
     * Default value if param not found
     * @return array|string|null
     */
    protected function getParam($name = null, $defaultValue = null)
    {
        $params = $this->getParams();
        if ($name === null) {
            return $params;
        } else {
            if(isset($params[$name])) {
                return $params[$name];
            } else {
                if ($val = ArrayHelper::getValue($params, $name))
                    return $val;
                return $defaultValue;
            }
        }
    }

    /**
     * Check all required params and returns as array
     * @param array $params
     * @return array
     */
    protected function validateRequiredParams(array $params) {
        $res = [];
        foreach ($params as $param) {
            if(($val = $this->getParam($param)) === null) {
                $this->throwError('Required Param '.$param.' is not set', 422);
            }
            $res[] = $val;
        }
        return $res;
    }

    /**
     * Is AJAX Request?
     * @return bool
     */
    protected function isAjaxRequest()
    {
        return Yii::$app->getRequest()->isAjax;
    }

    /**
     * Wrapper for request
     * @param callable $action
     * @param array $options
     * @return array
     */
    public function wrap(callable $action, array $options = [])
    {
        $options = array_merge([
            'mask_exceptions' => !YII_DEBUG,
            'exceptionHandles' => [],
            'log_handled' => true,
            'log_unhandled' => true,
            'trace_exceptions' => false
        ], $options);
        try {
            $result = $action();
            if (!$result) {
                $result = [];
            }

            return $result;
        } catch (ApiException $e) {
            $this->throwError($e->getMessage(), $e->getCode(), [], $options['log_handled']);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            $extra = [];
            if ($options['mask_exceptions']) {
                $message = 'Request Error';
                if ($options['exceptionHandles']) {
                    foreach ($options['exceptionHandles'] as $handledCode => $handledMessage) {
                        if ($e->getCode() === $handledCode) {
                            $message = $handledMessage;
                        }
                    }
                }
            } else {
                $extra['type'] = get_class($e);
                if (YII_DEBUG) {
                    $extra['trace'] = $e->getTraceAsString();
                }
            }
            Yii::error(sprintf("<b>Api Request Error:</b> %s\n" .
                "<b>Path:</b> <code>%s</code>\n" .
                "<b>Type:</b> %s\n" .
                "<b>Trace:</b> <code>%s</code>\n",
                $e->getMessage(),
                $_SERVER['REQUEST_URI'] ?? '?',
                get_class($e),
                $e->getTraceAsString()
            ), $this->logCategory);
            $this->throwError($message, $e->getCode(), $extra, false);
        }
    }

    /**
     * Stop request execution with error
     * @param string $error
     * Error message
     * @param int $code
     * Error code
     * @param array $extraData
     * Extra response data
     * @param bool $log
     * Log Error
     * @param bool $final
     * Is temporary or final error
     * @param int|null $responseCode
     * Set server response code
     * @noinspection JsonEncodingApiUsageInspection
     * @noinspection ReturnTypeCanBeDeclaredInspection
     * @noinspection PhpUnhandledExceptionInspection
     */
    public function throwError(
        string $error,
        int $code = 0,
        array $extraData = [],
        bool $log = true,
        bool $final = true,
        ?int $responseCode = 500
    ) {
        if ($log) {
            $params = property_exists($this, 'apiRequestParams') ? $this->apiRequestParams : Yii::$app->request->params;
            $msg = sprintf("<b>Api Request Error:</b> %s\n" .
                "<b>Path:</b> <code>%s</code>\n" .
                "<b>Request:</b> <code>%s</code>",
                $error,
                Yii::$app->getRequest()->getPathInfo(),
                json_encode($params, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)
            );
            if (!empty($extraData)) {
                $msg .= "\nExtra: <code>" . json_encode($extraData, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).'</code>';
            }
            Yii::error($msg, $this->logCategory);
        }
        if ($responseCode) {
            http_response_code($responseCode);
        }
        $this->finishAjaxAction(array_merge([
            'success' => false,
            'final' => $final,
            'error' => $error,
            'code' => $code
        ], $extraData));
    }

    /**
     * Finish API Request with response
     * @param array $result
     * Array with response data
     * @param bool $time
     * Add time field with current timestamp
     * @throws \yii\base\InvalidConfigException
     */
    public function finishAjaxAction($result = [], $time = false)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        header("Content-Type: application/json; charset=UTF-8", true);
        if (!is_array($result)) {
            $result = [];
        }
        $result = array_merge([
            'success' => true
        ], $result);
        if ($time) {
            $result['time'] = time();
        }
        $category = property_exists($this, 'logCategory') ? $this->logCategory : 'api';
        Yii::info(sprintf(' << Api Response: %s; Params: %s',
            Yii::$app->getRequest()->getPathInfo(),
            json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)),
        $category);
        $response = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        echo $response;
        exit();
    }

}