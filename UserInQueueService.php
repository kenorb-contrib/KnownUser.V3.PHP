<?php 
namespace QueueIT\KnownUserV3\SDK;

require_once('Models.php');
require_once('UserInQueueStateCookieRepository.php');
require_once('QueueITHelpers.php');

interface IUserInQueueService
{
    public function validateQueueRequest(
        $currentPageUrl,
        $queueitToken,
        QueueEventConfig $config,
        $customerId,
        $secretKey);

    public function validateCancelRequest($targetUrl,
            CancelEventConfig $cancelConfig, 
            $customerId, 
            $secretKey);

    public function extendQueueCookie(
        $eventId,
        $cookieValidityMinute,
        $cookieDomain,
        $secretKey);       
}

class UserInQueueService implements IUserInQueueService
{
    const SDK_VERSION = "3.3.0";
    private $userInQueueStateRepository;

    function __construct(IUserInQueueStateRepository $userInQueueStateRepository) {
        $this->userInQueueStateRepository = $userInQueueStateRepository;
    }

    public function validateQueueRequest(
        $targetUrl,
        $queueitToken,
        QueueEventConfig $config,
        $customerId,
        $secretKey) {
        $state = $this->userInQueueStateRepository->getState($config->eventId, $secretKey);

        if ($state->isValid) {
            if ($state->isStateExtendable && $config->extendCookieValidity) {
                $this->userInQueueStateRepository->store(
                    $config->eventId,
                    $state->queueId,
                    true,
                    $config->cookieValidityMinute,
                    !Utils::isNullOrEmptyString($config->cookieDomain) ? $config->cookieDomain : '',
                    $secretKey);
            }
            $result =  new RequestValidationResult(ActionTypes::QueueAction,$config->eventId,$state->queueId, NULL);
            return $result;
        }

        if(!Utils::isNullOrEmptyString($queueitToken)) {
            $queueParams = QueueUrlParams::extractQueueParams($queueitToken);
            return $this->getQueueITTokenValidationResult($customerId, $targetUrl, $config->eventId, $secretKey, $config, $queueParams);
        } else {
            return $this->getInQueueRedirectResult($customerId, $targetUrl, $config);
        }
    }

    private function getQueueITTokenValidationResult(
         $customerId,
         $targetUrl,
         $eventId,
         $secretKey,
         QueueEventConfig $config,
         QueueUrlParams $queueParams) {
        $calculatedHash = hash_hmac('sha256', $queueParams->queueITTokenWithoutHash, $secretKey);
        if (strtoupper($calculatedHash) != strtoupper($queueParams->hashCode)) {
            return $this->getVaidationErrorResult($customerId, $targetUrl, $config, $queueParams, "hash");
        }

        if (strtoupper($queueParams->eventId) != strtoupper($eventId)) {
            return $this->getVaidationErrorResult($customerId, $targetUrl, $config, $queueParams, "eventid");
        }

        if ($queueParams->timeStamp < time()) {
            return $this->getVaidationErrorResult($customerId, $targetUrl, $config, $queueParams, "timestamp");
        }

        $this->userInQueueStateRepository->store(
            $config->eventId,
            $queueParams->queueId,
            $queueParams->extendableCookie,
            !is_null( $queueParams->cookieValidityMinute) ? $queueParams->cookieValidityMinute : $config->cookieValidityMinute,
            !Utils::isNullOrEmptyString($config->cookieDomain) ? $config->cookieDomain : '',
            $secretKey);

            $result =  new RequestValidationResult(ActionTypes::QueueAction,$config->eventId, $queueParams->queueId, NULL);
            return $result;
    }

    private function getVaidationErrorResult(
        $customerId,
        $targetUrl,
        QueueEventConfig $config,
        QueueUrlParams $qParams,
        $errorCode) {
        $query =$this->getQueryString($customerId,  $config->eventId,$config->version,$config->culture,$config->layoutName)
            ."&queueittoken=" .$qParams->queueITToken
            ."&ts=" . time()
            .(!Utils::isNullOrEmptyString($targetUrl) ? ("&t=". urlencode( $targetUrl)) : "");
        $domainAlias = $config->queueDomain;
        if (substr($domainAlias, -1) !== "/") {
            $domainAlias = $domainAlias . "/";
        }
        $redirectUrl = "https://". $domainAlias. "error/". $errorCode. "/?" .$query;
        $result = new RequestValidationResult(ActionTypes::QueueAction,$config->eventId, null, $redirectUrl);

        return $result;
    }

    private function getInQueueRedirectResult($customerId, $targetUrl, QueueEventConfig $config) {
        $redirectUrl = "https://". $config->queueDomain ."/?" 
            .$this->getQueryString($customerId, $config->eventId,$config->version,$config->culture,$config->layoutName)
            .(!Utils::isNullOrEmptyString($targetUrl) ? "&t=". urlencode( $targetUrl) : "");
        $result = new RequestValidationResult(ActionTypes::QueueAction,$config->eventId, null, $redirectUrl);

        return $result;
    }

    private function getQueryString($customerId,
            $eventId,
            $configVersion,
            $culture,
            $layoutName) {
        $queryStringList = array();
        array_push($queryStringList,"c=".urlencode($customerId));
        array_push($queryStringList,"e=".urlencode($eventId));
        array_push($queryStringList,"ver=v3-php-".UserInQueueService::SDK_VERSION); 
        array_push($queryStringList,"cver=". (!is_null($configVersion)?$configVersion:'-1'));

        if (!Utils::isNullOrEmptyString($culture)) {
            array_push($queryStringList, "cid=" . urlencode($culture));
        }

        if (!Utils::isNullOrEmptyString($layoutName)) {
            array_push($queryStringList, "l=" . urlencode($layoutName));
        }

        return implode("&", $queryStringList);
    }



    public function extendQueueCookie(
        $eventId,
        $cookieValidityMinute,
        $cookieDomain,
        $secretKey) {
        $this->userInQueueStateRepository->extendQueueCookie($eventId, $cookieValidityMinute, $cookieDomain, $secretKey);
    }
    public function validateCancelRequest($targetUrl,CancelEventConfig $cancelConfig,$customerId,$secretKey)
        {
            $state = $this->userInQueueStateRepository->getState($cancelConfig->eventId, $secretKey);
            if ($state->isValid)
            {
                $this->userInQueueStateRepository->cancelQueueCookie($cancelConfig->eventId, $cancelConfig->cookieDomain);
                $query = $this->getQueryString($customerId, $cancelConfig->eventId, $cancelConfig->version,NULL,NULL)
                         .(!Utils::isNullOrEmptyString($targetUrl) ? ("&r=". urlencode($targetUrl)) : "");
                $domainAlias = $cancelConfig->queueDomain;
                if (substr($domainAlias, -1) !== "/") {
                        $domainAlias = $domainAlias . "/";
                }
                
                $redirectUrl = "https://" . $domainAlias . "cancel/" . $customerId . "/" . $cancelConfig->eventId . "/?" . $query;
                return new RequestValidationResult(ActionTypes::CancelAction,$cancelConfig->eventId, $state->queueId, $redirectUrl );
            }
            else
            {
                return new RequestValidationResult(ActionTypes::CancelAction,$cancelConfig->eventId,NULL,NULL);
            }

        }
}
