<?php
/**
 * Abstract Api Controller for handling API Requests
 * User: Andrey M.
 * Email: prowebcraft@gmail.com
 * Date: 17.03.2021 18:11
 */

namespace prowebcraft\yii2apicontroller;

use Yii as Yii;
use yii\base\InvalidRouteException;
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

    /**
     * Request bootstrap & validation
     * @param $action
     * @return mixed
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\web\BadRequestHttpException
     */
    public function beforeAction($action)
    {
        if ($this->allowCors || ($this->allowCorsInDev && YII_ENV === 'dev')) {
            header('Access-Control-Allow-Origin: *', true);
            header('Access-Control-Allow-Headers: *', true);
        }
        if (\Yii::$app->getRequest()->isOptions) {
            exit(); // this is preflight OPTIONS request
        }

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
        $res = $this->wrap(function () use ($id, $params) {
            $action = $this->createAction($id);
            if ($action === null) {
                throw new InvalidRouteException('Unable to resolve the request: ' . $this->getUniqueId() . '/' . $id);
            }
            if (method_exists($this, $action->actionMethod)) {
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
                    $params[$parameter->name] = $val;
                }
            }
            return parent::runAction($id, $params);
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
    public function wrap(callable $action, $options = [])
    {
        $options = array_merge([
            'mask_exceptions' => false,
            'exceptionHandles' => [],
            'log_handled' => true,
            'log_unhandled' => true,
            'trace_exceptions' => false
        ], $options);
        $logHandled = $options['log_handled'];
        $logUnhandled = $options['log_handled'];
        try {
            $result = $action();
            if (!$result) {
                $result = [];
            }
        } catch (ApiException $e) {
            $result = [
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ];
            if ($logHandled) {
                Yii::error($e->getMessage(), 'ajax');
            }
        } catch (\Error $e) {
            $result = $this->handleException($e, $options);
        } catch (\ErrorException $e) {
            $result = $this->handleException($e, $options);
        }
        return $result;
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
     * @throws \yii\base\InvalidConfigException
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
            $msg = sprintf("Api Request Error:\n" .
                "Path: %s\n" .
                "Error: %s\n" .
                "Request: %s",
                Yii::$app->getRequest()->getPathInfo(),
                $error, json_encode($params)
            );
            Yii::error($msg, 'api');
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

    /**
     * Handle exception
     * @param array $options
     * @param \Exception|\Error $e
     * @return array
     */
    protected function handleException($e, $options = [])
    {
        if ($options['mask_exceptions']) {
            $message = 'Request Error';
            if ($options['exceptionHandles']) {
                foreach ($options['exceptionHandles'] as $handledCode => $handledMessage) {
                    if ($e->getCode() == $handledCode) {
                        $message = $handledMessage;
                    }
                }
            }
            $result = [
                'success' => false,
                'error' => $message,
                'code' => $e->getCode()
            ];
        } else {
            $result = [
                'success' => false,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'type' => get_class($e)
            ];
            if (YII_DEBUG) {
                $result['trace'] = $e->getTraceAsString();
            }
        }
        if ($options['log_unhandled']) {
            $error = $e->getMessage();
            if ($options['trace_exceptions']) {
                $error .= "\nTrace: {$e->getTraceAsString()}";
            }
            Yii::error($error, 'ajax');
        }
        return $result;
    }

}