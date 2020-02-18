<?php

namespace AlterPHP\EasyAdminExtensionBundle\Controller;

use AlterPHP\EasyAdminExtensionBundle\Security\AdminAuthorizationChecker;
use EasyCorp\Bundle\EasyAdminBundle\Controller\EasyAdminController as BaseEasyAdminControler;
use EasyCorp\Bundle\EasyAdminBundle\Event\EasyAdminEvents;
use League\Uri\Modifiers\RemoveQueryParams;
use League\Uri\Schemes\Http;
use Symfony\Component\HttpFoundation\JsonResponse;
use AlterPHP\EasyAdminExtensionBundle\Model\Tab\Tab;
use AlterPHP\EasyAdminExtensionBundle\Model\Tab\TabContent;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;

class EasyAdminController extends BaseEasyAdminControler
{
    public static function getSubscribedServices(): array
    {
        return \array_merge(parent::getSubscribedServices(), [AdminAuthorizationChecker::class]);
    }

    protected function embeddedListAction()
    {
        $this->dispatch(EasyAdminEvents::PRE_LIST);

        $maxResults = (int) $this->request->query->get('max-results', $this->config['list']['max_results']);

        $paginator = $this->findAll($this->entity['class'], $this->request->query->get('page', 1), $maxResults, $this->request->query->get('sortField'), $this->request->query->get('sortDirection'), $this->entity['list']['dql_filter']);

        $this->dispatch(EasyAdminEvents::POST_LIST, ['paginator' => $paginator]);

        // Filter displaid columns
        $hiddenFields = $this->request->query->get('hidden-fields', []);
        $fields = \array_filter(
            $this->entity['list']['fields'],
            function ($name) use ($hiddenFields) {
                return !\in_array($name, $hiddenFields);
            },
            ARRAY_FILTER_USE_KEY
        );

        // Removes existing referer
        $baseMasterRequestUri = !$this->request->isXmlHttpRequest()
                            ? $this->get('request_stack')->getMasterRequest()->getUri()
                            : $this->request->headers->get('referer');
        $baseMasterRequestUri = Http::createFromString($baseMasterRequestUri);
        $removeRefererModifier = new RemoveQueryParams(['referer']);
        $masterRequestUri = $removeRefererModifier->process($baseMasterRequestUri);

        $requestParameters = $this->request->query->all();
        $requestParameters['referer'] = (string) $masterRequestUri;

        $viewVars = [
            'paginator' => $paginator,
            'fields' => $fields,
            '_request_parameters' => $requestParameters,
        ];

        return $this->executeDynamicMethod(
            'render<EntityName>Template',
            ['embeddedList', $this->entity['embeddedList']['template'], $viewVars]
        );
    }

    /**
     * {@inheritdoc}
     *
     * @throws \Symfony\Component\Security\Core\Exception\AccessDeniedException
     */
    protected function isActionAllowed($actionName)
    {
        switch ($actionName) {
            // autocomplete action is mapped to list action for access permissions
            case 'autocomplete':
            // filters (EasyAdmin new list filters) action is mapped to list action for access permissions
            case 'filters':
            // embeddedList action is mapped to list action for access permissions
            case 'embeddedList':
                $actionName = 'list';
                break;
            // newAjax action is mapped to new action for access permissions
            case 'newAjax':
                $actionName = 'new';
                break;
            default:
                break;
        }

        // Get item for edit/show or custom actions => security voters may apply
        $easyadmin = $this->request->attributes->get('easyadmin');
        $subject = $easyadmin['item'] ?? null;
        $this->get(AdminAuthorizationChecker::class)->checksUserAccess($this->entity, $actionName, $subject);

        return parent::isActionAllowed($actionName);
    }

    /**
     * The method that is executed when the user performs a 'new ajax' action on an entity.
     *
     * @return JsonResponse
     */
    protected function newAjaxAction()
    {
        $this->dispatch(EasyAdminEvents::PRE_NEW);

        $entity = $this->executeDynamicMethod('createNew<EntityName>Entity');
        $easyadmin = \array_merge($this->request->attributes->get('easyadmin'), ['item' => $entity]);
        $this->request->attributes->set('easyadmin', $easyadmin);

        $fields = $this->entity['new']['fields'];
        $newForm = $this->executeDynamicMethod('create<EntityName>NewForm', [$entity, $fields]);
        $newForm->handleRequest($this->request);
        if ($newForm->isSubmitted() && $newForm->isValid()) {
            $this->dispatch(EasyAdminEvents::PRE_PERSIST, ['entity' => $entity]);
            $this->executeDynamicMethod('persist<EntityName>Entity', [$entity]);
            $this->dispatch(EasyAdminEvents::POST_PERSIST, ['entity' => $entity]);

            return new JsonResponse(['option' => ['id' => $entity->getId(), 'text' => (string) $entity]]);
        }

        $this->dispatch(EasyAdminEvents::POST_NEW, ['entity_fields' => $fields, 'form' => $newForm, 'entity' => $entity]);

        $parameters = ['form' => $newForm->createView(), 'entity_fields' => $fields, 'entity' => $entity];
        $templatePath = '@EasyAdminExtension/default/new_ajax.html.twig';
        if (isset($this->entity['templates']['new_ajax'])) {
            $templatePath = $this->entity['templates']['new_ajax'];
        }

        return new JsonResponse(['html' => $this->renderView($templatePath, $parameters)]);
    }

    /**
     * The method that is executed when the user performs a 'show' action on an entity.
     *
     * @return Response
     */
    protected function showAction()
    {
        $this->dispatch(EasyAdminEvents::PRE_SHOW);

        $id = $this->request->query->get('id');
        $easyadmin = $this->request->attributes->get('easyadmin');
        $entity = $easyadmin['item'];

        $fields = $this->entity['show']['fields'];
        $deleteForm = $this->createDeleteForm($this->entity['name'], $id);
        $tab = null;
        $currentTab = null;
        if($this->request->getSession()->has(\AlterPHP\EasyAdminExtensionBundle\Model\Tab\Tab::NAME_CURRENT_TAB)){
            $currentTab = $this->request->getSession()->get(\AlterPHP\EasyAdminExtensionBundle\Model\Tab\Tab::NAME_CURRENT_TAB);
        }
        foreach ($fields as $field => $metadata) {
            if($metadata["type"] == Tab::TAB_TITLE){
                $tab = Tab::createFromMetadata($metadata);
                unset($fields[$field]);
            }else if($metadata["type"] == Tab::TAB_CONTENT){
                $routeParameters = isset($metadata["route_parameters"]) ? $metadata["route_parameters"] : [];
                $routeParameters["id"]  = $id;
                $routeParameters["entity"]  = $this->request->query->get('entity');
                $routeParameters["action"]  = $this->request->query->get('action');
                $metadata["route_parameters"] = $routeParameters;
                $tabContent = TabContent::createFromMetadata($metadata);
                if($tab === null){
                    $tab = Tab::createFromMetadata();
                }
                $tab->addTabContent($tabContent);
                unset($fields[$field]);
            }else if($tab !== null && isset($metadata["property"])){
                $tabContent = $tab->getLastTabContent();
                $tabContent->addField($field,$metadata);
                unset($fields[$field]);
            }else if($tab !== null && !isset($metadata["property"])){
                $tabContent = $tab->getLastTabContent();
                $tabContent->addField($field,$metadata);
                unset($fields[$field]);
            }
        }
        if($tab !== null){
            $tab->resolveCurrentTab($currentTab);
        }
        $this->dispatch(EasyAdminEvents::POST_SHOW, array(
            'deleteForm' => $deleteForm,
            'fields' => $fields,
            'entity' => $entity,
        ));

        $parameters = array(
            'entity' => $entity,
            'fields' => $fields,
            'delete_form' => $deleteForm->createView(),
            'tab' => $tab,
        );

        return $this->executeDynamicMethod('render<EntityName>Template', array('show', $this->entity['templates']['show'], $parameters));
    }
    
    /**
     * @Route("/tab", name="easyadmin_tab")
     */
    public function tabAction(\Symfony\Component\HttpFoundation\Request $request)
    {
        if($request->query->has(\AlterPHP\EasyAdminExtensionBundle\Model\Tab\Tab::NAME_CURRENT_TAB)){
            $request->getSession()->set(\AlterPHP\EasyAdminExtensionBundle\Model\Tab\Tab::NAME_CURRENT_TAB,$request->query->get(\AlterPHP\EasyAdminExtensionBundle\Model\Tab\Tab::NAME_CURRENT_TAB));
        }
        return new \Symfony\Component\HttpFoundation\JsonResponse();
    }
}
