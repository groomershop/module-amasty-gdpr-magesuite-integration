<?php

declare(strict_types=1);

namespace Groomershop\AmastyGdprMageSuiteIntegration\Model\Consent\DataProvider;

use Amasty\Gdpr\Api\ConsentRepositoryInterface;
use Amasty\Gdpr\Api\Data\ConsentInterface;
use Amasty\Gdpr\Api\PolicyRepositoryInterface;
use Amasty\Gdpr\Model\Consent\ConsentStore\ConsentStore;
use Amasty\Gdpr\Model\Source\ConsentLinkType;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;

class ConsentPolicyContentResolver extends \Amasty\Gdpr\Model\Consent\DataProvider\ConsentPolicyContentResolver
{
    const DATA_TITLE = 'title';
    const DATA_CONTENT = 'content';

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var PolicyRepositoryInterface
     */
    private $policyRepository;

    /**
     * @var PageRepositoryInterface
     */
    private $pageRepository;

    /**
     * @var ConsentRepositoryInterface
     */
    private $consentRepository;

    /**
     * @var \Magento\Framework\View\Element\BlockFactory
     */
    private $blockFactory;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    public function __construct(
        StoreManagerInterface $storeManager,
        PageRepositoryInterface $pageRepository,
        PolicyRepositoryInterface $policyRepository,
        ConsentRepositoryInterface $consentRepository,
        \Magento\Framework\View\Element\BlockFactory $blockFactory,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->storeManager = $storeManager;
        $this->pageRepository = $pageRepository;
        $this->policyRepository = $policyRepository;
        $this->consentRepository = $consentRepository;
        $this->blockFactory = $blockFactory;
        $this->logger = $logger;
    }

    public function getConsentPolicyData(int $consentId): array
    {
        $storeId = (int)$this->storeManager->getStore()->getId();
        $consent = $this->consentRepository->getById($consentId, $storeId);
        $privacyLinkType = $consent->getPrivacyLinkType() ?: ConsentLinkType::PRIVACY_POLICY;

        switch ($privacyLinkType) {
            case ConsentLinkType::CMS_PAGE:
                return $this->getCmsPagePolicyData($consent);
            default:
                return $this->getGeneralPolicyData();
        }
    }

    public function getGeneralPolicyData(): array
    {
        $policy = $this->policyRepository->getCurrentPolicy(
            $this->storeManager->getStore()->getId()
        );

        return [
            self::DATA_TITLE => __('Privacy Policy'),
            self::DATA_CONTENT => $policy ? $policy->getContent() : ''
        ];
    }

    private function getCmsPagePolicyData(ConsentInterface $consent): array
    {
        if ($cmsPageId = (int)$consent->getStoreModel()->getData(ConsentStore::CMS_PAGE_ID)) {
            try {
                $cmsPage = $this->pageRepository->getById($cmsPageId);
                $contentConstructorContent = $this->renderContentConstructorContent($cmsPage);
                $title = $contentConstructorContent ? '' : ($cmsPage->getTitle() ?: __('Privacy Policy'));

                return [
                    self::DATA_TITLE => $title,
                    self::DATA_CONTENT => $contentConstructorContent ?: $cmsPage->getContent() ?: ''
                ];
            } catch (\Exception $e) {
                null;
            }
        }

        return [];
    }

    private function renderContentConstructorContent($subject)
    {
        $contentConstructorContent = $subject->getContentConstructorContent();

        if (empty($contentConstructorContent)) {
            return null;
        }

        $components = json_decode($contentConstructorContent, true);

        if (empty($components)) {
            return null;
        }

        $html = '';

        foreach ($components as $component) {
            try {
                $componentBlock = $this->blockFactory->createBlock(\MageSuite\ContentConstructorFrontend\Block\Component::class, [
                    'data' => $component
                ]);

                $html .= $componentBlock->toHtml();
            } catch (\Exception $exception) {
                $this->logger->warning($exception->getMessage());
            }
        }

        return $html;
    }
}
