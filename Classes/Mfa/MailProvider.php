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
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Mail\FluidEmail;
use TYPO3\CMS\Core\Mail\Mailer;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Core\View\ViewInterface;


class MailProvider implements MfaProviderInterface
{
    protected const DEFAULT_MAX_ATTEMPTS = 6;
    protected const DEFAULT_CODE_VALIDITY_PERIOD = 900;
    protected const LANGUAGE_FILE = 'LLL:EXT:mfa_email/Resources/Private/Language/locallang.xlf:';

    protected array $extensionConfiguration;

    protected ?ServerRequestInterface $request = null;

    public function __construct(
        protected readonly Context $context,
        protected readonly ResponseFactory $responseFactory,
        protected readonly ViewFactoryInterface $viewFactory,
        protected readonly Mailer $mailer,
        protected readonly FlashMessageService $flashMessageService,
        protected readonly LanguageServiceFactory $languageServiceFactory,
        ExtensionConfiguration $extensionConfiguration,
    ) {
        try {
            $configuration = $extensionConfiguration->get('mfa_email');
            $this->extensionConfiguration = is_array($configuration) ? $configuration : [];
        } catch (ExtensionConfigurationExtensionNotConfiguredException|ExtensionConfigurationPathDoesNotExistException) {
            // Not configured yet: use the safe defaults below.
            $this->extensionConfiguration = [];
        }
    }

    public function canProcess(ServerRequestInterface $request): bool
    {
        // Sending a code by e-mail has no further technical requirements.
        return true;
    }

    /**
     * Evaluate if the provider is activated
     */
    public function isActive(MfaProviderPropertyManager $propertyManager): bool
    {
        return (bool)$propertyManager->getProperty('active');
    }

    /**
     * Evaluate if the provider is temporarily locked
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
     */
    public function handleRequest(
        ServerRequestInterface     $request,
        MfaProviderPropertyManager $propertyManager,
        MfaViewType                $type
    ): ResponseInterface
    {
        $this->request = $request;
        $viewFactoryData = new ViewFactoryData(
            templateRootPaths: ['EXT:mfa_email/Resources/Private/Templates/Mfa'],
            request: $request,
        );
        $view = $this->viewFactory->create($viewFactoryData);
        $view->assign('providerIdentifier', $propertyManager->getIdentifier());

        switch ($type) {
            case MfaViewType::SETUP:
            case MfaViewType::EDIT:
                $output = $this->prepareEditView($view, $propertyManager);
                break;
            case MfaViewType::AUTH:
                $output = $this->prepareAuthView($request, $view, $propertyManager);
                break;
        }
        $response = $this->responseFactory->createResponse();
        $response->getBody()->write($output ?? '');

        return $response;
    }

    /**
     * Verify the given auth code
     */
    public function verify(ServerRequestInterface $request, MfaProviderPropertyManager $propertyManager): bool
    {
        if (!$this->isActive($propertyManager) || $this->isLocked($propertyManager)) {
            // Can not verify an inactive or locked provider
            return false;
        }

        $parsedBody = $request->getParsedBody();
        $input = $request->getQueryParams()['authCode'] ?? (is_array($parsedBody) ? ($parsedBody['authCode'] ?? '') : '');
        $authCodeInput = is_string($input) ? trim($input) : '';
        $properties = $propertyManager->getProperties();
        $storedAuthCode = is_string($properties['authCode'] ?? null) ? $properties['authCode'] : '';

        // Empty input or storage must never authenticate an MFA challenge.
        if ($authCodeInput === '' || $storedAuthCode === '') {
            return false;
        }

        // Discard expired and legacy codes without a creation timestamp.
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
                // Unlocking must not make the old code usable again.
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
     */
    public function activate(ServerRequestInterface $request, MfaProviderPropertyManager $propertyManager): bool
    {
        return $this->update($request, $propertyManager);
    }

    /**
     * Handle the unlock action by resetting the attempts provider property
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
     */
    public function update(ServerRequestInterface $request, MfaProviderPropertyManager $propertyManager): bool
    {
        if (!$this->canProcess($request)) {
            return false;
        }

        $parsedBody = $request->getParsedBody();
        $input = is_array($parsedBody) ? ($parsedBody['email'] ?? '') : '';
        $email = is_string($input) ? trim($input) : '';
        if (!$this->checkValidEmail($email)) {
            return false;
        }

        $properties = [
            'attempts' => 0,
            'authCode' => '',
            'authCodeCreated' => 0,
            'email' => $email,
            'active' => true,
        ];
        return $propertyManager->hasProviderEntry()
            ? $propertyManager->updateProperties($properties)
            : $propertyManager->createProviderEntry($properties);
    }

    /**
     * Set auth code to the properties and send the E-Mail to the user
     */
    protected function sendAuthCodeEmail(MfaProviderPropertyManager $propertyManager): void
    {
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
        }

        $recipient = (string)$propertyManager->getProperty('email', '');
        if (!$this->isEmailValid($recipient)) {
            return;
        }

        // TYPO3 13 uses FluidEmail rather than the TYPO3 14 email factory.
        $email = GeneralUtility::makeInstance(FluidEmail::class);
        if ($this->request !== null) {
            $email->setRequest($this->request);
        }
        $email
            ->to($recipient)
            ->setTemplate($this->getTemplateName('mailTemplateName'))
            ->assignMultiple([
                'authCode' => $authCode,
                'email' => $recipient,
                'layoutName' => $this->getTemplateName('mailLayoutName'),
            ]);

        // The template renders the subject while generating the body.
        $email->getHtmlBody(true);
        $email->subject($email->getSubject());

        $senderEmail = $this->getConfigurationString('mailSenderEmail');
        if ($senderEmail !== '') {
            $email->from(new Address($senderEmail, $this->getConfigurationString('mailSenderName')));
        }

        $this->mailer->send($email);
    }

    /**
     * Set the template and assign necessary variables for the edit view
     */
    protected function prepareEditView(ViewInterface $view, MfaProviderPropertyManager $propertyManager): string
    {
        $email = (string)$propertyManager->getProperty('email', '');
        if ($email === '') {
            $email = (string)($GLOBALS['BE_USER']->user['email'] ?? '');
        }

        $view->assignMultiple([
            'email' => $email,
            'lastUsed' => $this->getDateTime((int)$propertyManager->getProperty('lastUsed', 0)),
            'updated' => $this->getDateTime((int)$propertyManager->getProperty('updated', 0)),
        ]);

        return $view->render('Edit');
    }

    /**
     * Set the template and assign necessary variables for the auth view
     */
    protected function prepareAuthView(ServerRequestInterface $request, ViewInterface $view, MfaProviderPropertyManager $propertyManager): string
    {
        $queryParams = $request->getQueryParams();

        $this->sendAuthCodeEmail($propertyManager);
        $view->assignMultiple([
            'isLocked' => $this->isLocked($propertyManager),
            'resendLink' => '?' . http_build_query(array_merge($queryParams, ['resend' => '1'])),
        ]);

        return $view->render('Auth');
    }

    /**
     * Generates a random authentication code with 6 digits
     */
    protected function generateAuthCode(): string
    {
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    protected function isAuthCodeExpired(array $properties): bool
    {
        $validityPeriod = $this->getCodeValidityPeriod();
        if ($validityPeriod <= 0) {
            // Expiry explicitly disabled by configuration.
            return false;
        }

        $created = (int)($properties['authCodeCreated'] ?? 0);
        if ($created <= 0) {
            return true;
        }

        return ($this->getTimestamp() - $created) > $validityPeriod;
    }

    protected function getTimestamp(): int
    {
        return (int)$this->context->getPropertyFromAspect('date', 'timestamp');
    }

    /**
     * Return the timestamp as local time (date string) by applying the globally configured format
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
        if ($email === '') {
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

    protected function getConfigurationString(string $key): string
    {
        $value = $this->extensionConfiguration[$key] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    protected function getTemplateName(string $key, string $default = 'MfaEmail'): string
    {
        $name = $this->getConfigurationString($key);
        return $name !== '' ? $name : $default;
    }

    /**
     * Helper to display localized flash messages
     */
    protected function showLocalizedFlashMessage(string $messageKey): void
    {
        $flashMessage = new FlashMessage(
            $this->translate($messageKey . '.message'),
            $this->translate($messageKey . '.title'),
            ContextualFeedbackSeverity::ERROR,
            true
        );
        $this->flashMessageService->getMessageQueueByIdentifier()->addMessage($flashMessage);
    }

    /**
     * Helper to display localized flash messages
     */
    protected function translate(string $messageKey): string
    {
        // TYPO3 13's factory accepts user preferences, not createForBackendUser().
        return $this->languageServiceFactory
            ->createFromUserPreferences($GLOBALS['BE_USER'] ?? null)
            ->sL(self::LANGUAGE_FILE . $messageKey);
    }

    /**
     * Set maximum attempts or -1 to deactivate
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
     * Lifetime in seconds. Non-positive integer values explicitly disable expiry.
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
