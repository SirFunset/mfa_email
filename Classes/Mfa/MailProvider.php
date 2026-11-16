<?php

declare(strict_types=1);

namespace Ralffreit\MfaEmail\Mfa;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Mime\Address;
use TYPO3\CMS\Core\Authentication\Mfa\MfaProviderInterface;
use TYPO3\CMS\Core\Authentication\Mfa\MfaProviderPropertyManager;
use TYPO3\CMS\Core\Authentication\Mfa\MfaViewType;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\Exception\AspectNotFoundException;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Mail\FluidEmail;
use TYPO3\CMS\Core\Mail\Mailer;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;

class MailProvider implements MfaProviderInterface
{
    protected const DEFAULT_MAX_ATTEMPTS = 6;
    protected const DEFAULT_CODE_VALIDITY_PERIOD = 900;

    protected Context $context;
    protected ResponseFactory $responseFactory;
    protected array $extensionConfiguration;

    protected ServerRequestInterface $request;

    /**
     * @param Context $context
     * @param ResponseFactory $responseFactory
     * @param ExtensionConfiguration $extensionConfiguration
     */
    public function __construct(Context $context, ResponseFactory $responseFactory, ExtensionConfiguration $extensionConfiguration)
    {
        $this->context = $context;
        $this->responseFactory = $responseFactory;
        try {
            $this->extensionConfiguration = $extensionConfiguration->get('mfa_email');
        } catch (ExtensionConfigurationExtensionNotConfiguredException|ExtensionConfigurationPathDoesNotExistException) {
            $this->extensionConfiguration = [];
        }
    }

    /**
     * @param ServerRequestInterface $request
     * @return bool
     */
    public function canProcess(ServerRequestInterface $request): bool
    {
        // Sending a code by e-mail has no further technical requirements.
        return true;
    }

    /**
     * Evaluate if the provider is activated
     *
     * @param MfaProviderPropertyManager $propertyManager
     * @return bool
     */
    public function isActive(MfaProviderPropertyManager $propertyManager): bool
    {
        return (bool)$propertyManager->getProperty('active');
    }

    /**
     * Evaluate if the provider is temporarily locked
     *
     * @param MfaProviderPropertyManager $propertyManager
     * @return bool
     */
    public function isLocked(MfaProviderPropertyManager $propertyManager): bool
    {
        $attempts = (int)$propertyManager->getProperty('attempts', 0);

        // Assume the provider is locked in case the maximum attempts are exceeded.
        // A provider however can only be locked if set up - an entry exists in database.
        return $propertyManager->hasProviderEntry() && $attempts >= $this->getMaxAttempts();
    }

    /**
     * Initialize view and forward to the appropriate implementation
     *
     * @param ServerRequestInterface $request
     * @param MfaProviderPropertyManager $propertyManager
     * @param string $type
     * @return ResponseInterface
     */
    public function handleRequest(
        ServerRequestInterface $request,
        MfaProviderPropertyManager $propertyManager,
        string $type
    ): ResponseInterface {
        $this->request = $request;
        $view = GeneralUtility::makeInstance(StandaloneView::class);
        $view->setRequest($request);
        $view->setTemplateRootPaths(['EXT:mfa_email/Resources/Private/Templates/Mfa']);
        switch ($type) {
            case MfaViewType::SETUP:
            case MfaViewType::EDIT:
                $this->prepareEditView($view, $propertyManager);
                break;
            case MfaViewType::AUTH:
                $this->prepareAuthView($request, $view, $propertyManager);
                break;
        }
        $response = $this->responseFactory->createResponse();
        $response->getBody()->write($view->assign('providerIdentifier', $propertyManager->getIdentifier())->render());
        return $response;
    }

    /**
     * Verify the given auth code
     *
     * @param ServerRequestInterface $request
     * @param MfaProviderPropertyManager $propertyManager
     * @return bool
     * @throws AspectNotFoundException
     */
    public function verify(ServerRequestInterface $request, MfaProviderPropertyManager $propertyManager): bool
    {
        if (!$this->isActive($propertyManager) || $this->isLocked($propertyManager)) {
            // Can not verify an inactive or locked provider
            return false;
        }

        $parsedBody = $request->getParsedBody();
        $authCodeInput = $request->getQueryParams()['authCode']
            ?? (is_array($parsedBody) ? ($parsedBody['authCode'] ?? '') : '');
        $authCodeInput = is_string($authCodeInput) ? trim($authCodeInput) : '';
        $properties = $propertyManager->getProperties();
        $storedAuthCode = (string)($properties['authCode'] ?? '');

        if ($authCodeInput === '' || $storedAuthCode === '') {
            // Cannot verify when authCode was not saved or passed empty
            return false;
        }

        if ($this->isAuthCodeExpired($properties)) {
            $propertyManager->updateProperties(['authCode' => '', 'authCodeCreated' => 0]);
            return false;
        }

        if (!hash_equals($storedAuthCode, $authCodeInput)) {

            if (!isset($properties['attempts']) || !MathUtility::canBeInterpretedAsInteger($properties['attempts'])) {
                $properties['attempts'] = 0;
            }

            $properties['attempts']++;

            if ($properties['attempts'] >= $this->getMaxAttempts()) {
                // Reset the code
                $properties['authCode'] = '';
                $properties['authCodeCreated'] = 0;
            }

            $propertyManager->updateProperties($properties);

            return false;
        }

        $properties['authCode'] = '';
        $properties['authCodeCreated'] = 0;
        $properties['attempts'] = 0;
        $properties['lastUsed'] = $this->getTimestamp();

        return $propertyManager->updateProperties($properties);
    }

    /**
     * Activate the provider
     *
     * @param ServerRequestInterface $request
     * @param MfaProviderPropertyManager $propertyManager
     * @return bool
     */
    public function activate(ServerRequestInterface $request, MfaProviderPropertyManager $propertyManager): bool
    {
        return $this->update($request, $propertyManager);
    }

    /**
     * Handle the unlock action by resetting the attempts provider property
     *
     * @param ServerRequestInterface $request
     * @param MfaProviderPropertyManager $propertyManager
     * @return bool
     */
    public function unlock(ServerRequestInterface $request, MfaProviderPropertyManager $propertyManager): bool
    {
        if (!$this->isActive($propertyManager) || !$this->isLocked($propertyManager)) {
            return false;
        }
        return $propertyManager->updateProperties(['attempts' => 0]);
    }

    /**
     * Handle the deactivate action
     *
     * @param ServerRequestInterface $request
     * @param MfaProviderPropertyManager $propertyManager
     * @return bool
     */
    public function deactivate(ServerRequestInterface $request, MfaProviderPropertyManager $propertyManager): bool
    {
        if (!$this->isActive($propertyManager)) {
            return false;
        }
        return $propertyManager->updateProperties(['active' => false]);
    }

    /**
     * Update the provider data
     *
     * @param ServerRequestInterface $request
     * @param MfaProviderPropertyManager $propertyManager
     * @return bool
     */
    public function update(ServerRequestInterface $request, MfaProviderPropertyManager $propertyManager): bool
    {
        if (!$this->canProcess($request)) {
            return false;
        }

        $parsedBody = $request->getParsedBody();
        $email = is_array($parsedBody) ? ($parsedBody['email'] ?? '') : '';
        $email = is_string($email) ? trim($email) : '';
        if (!$this->checkValidEmail($email)) {
            return false;
        }

        $properties = [
            'attempts' => 0,
            'authCode' => '',
            'authCodeCreated' => 0,
            'email' => $email,
            'active' => true
        ];
        return $propertyManager->hasProviderEntry()
            ? $propertyManager->updateProperties($properties)
            : $propertyManager->createProviderEntry($properties);
    }

    /**
     * Set auth code to the properties and send the E-Mail to the user
     * @param MfaProviderPropertyManager $propertyManager
     * @param bool $resend
     */
    protected function sendAuthCodeEmail(MfaProviderPropertyManager $propertyManager, bool $resend = false): void
    {
        $newAuthCode = false;
        if (!$this->isActive($propertyManager) || $this->isLocked($propertyManager)) {
            return;
        }
        $authCode = (string)$propertyManager->getProperty('authCode', '');

        if ($authCode === '' || $this->isAuthCodeExpired($propertyManager->getProperties())) {
            $authCode = $this->generateAuthCode();
            if (!$propertyManager->updateProperties([
                'authCode' => $authCode,
                'authCodeCreated' => $this->getTimestamp(),
            ])) {
                return;
            }
            $newAuthCode = true;
        }

        if ($newAuthCode || $resend) {
            $mailLayoutName = $this->getTemplateName('mailLayoutName');
            $mailTemplateName = $this->getTemplateName('mailTemplateName');
            $recipient = (string)$propertyManager->getProperty('email', '');
            if ($recipient === '') {
                return;
            }

            $email = GeneralUtility::makeInstance(FluidEmail::class);
            $email->setRequest($this->request);
            $email
                ->to($recipient)
                ->setTemplate($mailTemplateName)
                ->assignMultiple([
                    'authCode' => $authCode,
                    'email' => $recipient,
                    'layoutName' => $mailLayoutName
                ]);

            $email->getHtmlBody(true); // Generate Subject
            $email->subject($email->getSubject());

            $senderEmail = trim((string)($this->extensionConfiguration['mailSenderEmail'] ?? ''));
            if ($senderEmail !== '') {
                $senderName = trim((string)($this->extensionConfiguration['mailSenderName'] ?? ''));
                $email->from(new Address($senderEmail, $senderName));
            }

            GeneralUtility::makeInstance(Mailer::class)->send($email);
        }
    }

    /**
     * Set the template and assign necessary variables for the edit view
     *
     * @param StandaloneView $view
     * @param MfaProviderPropertyManager $propertyManager
     */
    protected function prepareEditView(StandaloneView $view, MfaProviderPropertyManager $propertyManager): void
    {
        $view->setTemplate('Edit');
        $view->assignMultiple([
            'email' => (string)($propertyManager->getProperty('email') ?: ($GLOBALS['BE_USER']->user['email'] ?? '')),
            'lastUsed' => $this->getDateTime((int)$propertyManager->getProperty('lastUsed', 0)),
            'updated' => $this->getDateTime((int)$propertyManager->getProperty('updated', 0)),
        ]);
    }

    /**
     * Set the template and assign necessary variables for the auth view
     *
     * @param ServerRequestInterface $request
     * @param StandaloneView $view
     * @param MfaProviderPropertyManager $propertyManager
     */
    protected function prepareAuthView(ServerRequestInterface $request, StandaloneView $view, MfaProviderPropertyManager $propertyManager): void
    {
        $queryParams = $request->getQueryParams();
        $resend = !empty($queryParams['resend']) && $queryParams['resend'] === '1';

        $this->sendAuthCodeEmail($propertyManager, $resend);
        $view->setTemplate('Auth');
        $view->assignMultiple([
            'isLocked' => $this->isLocked($propertyManager),
            'resendLink' => '?' . http_build_query(array_merge($queryParams, ['resend' => '1'])),
        ]);
    }

    /**
     * Generates an random authentication code with 6 digits
     *
     * @return string
     */
    protected function generateAuthCode(): string
    {
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    protected function isAuthCodeExpired(array $properties): bool
    {
        $validityPeriod = $this->getCodeValidityPeriod();
        if ($validityPeriod <= 0) {
            return false;
        }
        $created = (int)($properties['authCodeCreated'] ?? 0);
        return $created <= 0 || ($this->getTimestamp() - $created) > $validityPeriod;
    }

    protected function getTimestamp(): int
    {
        return (int)$this->context->getPropertyFromAspect('date', 'timestamp');
    }

    /**
     * Return the timestamp as local time (date string) by applying the globally configured format
     *
     * @param int $timestamp
     * @return string
     */
    protected function getDateTime(int $timestamp): string
    {
        if ($timestamp === 0) {
            return '';
        }

        return date(
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'] . ' ' . $GLOBALS['TYPO3_CONF_VARS']['SYS']['hhmm'],
            $timestamp
        ) ?: '';
    }

    protected function checkValidEmail(string $email): bool
    {

        $messageKey = null;
        if (empty($email)) {
            $messageKey = 'error.email.empty';
        } elseif (!$this->isEmailValid($email)) {
            $messageKey = 'error.email.notvalid';
        }

        if ($messageKey !== null) {
            $this->showLocalizedFlashMessage($messageKey);
            return false;
        }

        return true;
    }

    public function isEmailValid(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    protected function getTemplateName(string $key, string $default = 'MfaEmail'): string
    {
        $name = trim((string)($this->extensionConfiguration[$key] ?? ''));
        return $name !== '' ? $name : $default;
    }


    /**
     * Helper to display localized flash messages
     * @param string $messageKey
     */
    protected function showLocalizedFlashMessage(string $messageKey): void
    {
        $errorMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            $this->showLocalizedMessage($messageKey . '.message'),
            $this->showLocalizedMessage($messageKey . '.title'),
            ContextualFeedbackSeverity::ERROR,
            true
        );
        $flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
        $messageQueue = $flashMessageService->getMessageQueueByIdentifier();
        $messageQueue->addMessage($errorMessage);
    }

    /**
     * Helper to display localized flash messages
     * @param string $messageKey
     */
    protected function showLocalizedMessage(string $messageKey, array $params = []): string
    {
        return GeneralUtility::makeInstance(LanguageServiceFactory::class)
            ->createFromUserPreferences($GLOBALS['BE_USER'] ?? null)
            ->sL('LLL:EXT:mfa_email/Resources/Private/Language/locallang.xlf:' . $messageKey);
    }

    /**
     * Set maximum attempts or -1 to deactivate
     *
     * @return int
     */
    protected function getMaxAttempts(): int
    {
        if (!isset($this->extensionConfiguration['maxAttempts'])
            || !MathUtility::canBeInterpretedAsInteger($this->extensionConfiguration['maxAttempts'])
        ) {
            return self::DEFAULT_MAX_ATTEMPTS;
        }
        $maxAttempts = (int)$this->extensionConfiguration['maxAttempts'];
        if ($maxAttempts === -1) {
            return PHP_INT_MAX;
        }
        return $maxAttempts > 0 ? $maxAttempts : self::DEFAULT_MAX_ATTEMPTS;
    }

    /**
     * A non-positive configured value explicitly disables expiry, as on the main line.
     */
    protected function getCodeValidityPeriod(): int
    {
        if (!isset($this->extensionConfiguration['codeValidityPeriod'])
            || !MathUtility::canBeInterpretedAsInteger($this->extensionConfiguration['codeValidityPeriod'])
        ) {
            return self::DEFAULT_CODE_VALIDITY_PERIOD;
        }
        $validityPeriod = (int)$this->extensionConfiguration['codeValidityPeriod'];
        return $validityPeriod > 0 ? $validityPeriod : 0;
    }
}
