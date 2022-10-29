<?php

/*
 * This file is part of the Klipper package.
 *
 * (c) François Pluchino <francois.pluchino@klipper.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Klipper\Bundle\ApiPortalBundle\Controller;

use Klipper\Bundle\ApiBundle\Action\Create;
use Klipper\Bundle\ApiBundle\Action\Update;
use Klipper\Bundle\ApiBundle\Controller\Action\Listener\FormPostSubmitListenerInterface;
use Klipper\Bundle\ApiBundle\Controller\ControllerHelper;
use Klipper\Bundle\ApiBundle\Exception\InvalidArgumentException;
use Klipper\Bundle\ApiPortalBundle\Form\Type\CreatePortalUserType;
use Klipper\Bundle\ApiPortalBundle\Form\Type\InvitationRequestType;
use Klipper\Bundle\ApiPortalBundle\User\ChangePasswordHelper;
use Klipper\Component\Content\ContentManagerInterface;
use Klipper\Component\Metadata\MetadataManagerInterface;
use Klipper\Component\Model\Traits\EnableInterface;
use Klipper\Component\Portal\Model\PortalUserInterface;
use Klipper\Component\Resource\Handler\FormConfig;
use Klipper\Component\Security\Model\UserInterface;
use Klipper\Component\Security\Organizational\OrganizationalContextInterface;
use Klipper\Component\SecurityOauth\Scope\ScopeVote;
use Klipper\Component\Translation\ExceptionTranslatorInterface;
use Klipper\Component\User\Model\Traits\ProfileableInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Entity;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Component\Form\Event\PostSubmitEvent;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/**
 * @author François Pluchino <francois.pluchino@klipper.dev>
 */
class PortalUserController
{
    /**
     * Invite an existing user for the portal.
     *
     * @Route("/portal_users/invite", methods={"POST"})
     * @Security("is_granted('perm:create', 'App\\Entity\\PortalUser')")
     *
     * @throws
     */
    public function invite(
        ControllerHelper $helper,
        ExceptionTranslatorInterface $exceptionTranslator,
        OrganizationalContextInterface $orgContext
    ): Response {
        if (class_exists(ScopeVote::class)) {
            $helper->denyAccessUnlessGranted(new ScopeVote('meta/portal_user'));
        }

        $portalUserDomain = $helper->getDomain(PortalUserInterface::class);
        $userDomain = $helper->getDomain(UserInterface::class);
        $currentOrg = $orgContext->getCurrentOrganization();

        if (null === $currentOrg) {
            throw $helper->createNotFoundException();
        }

        try {
            $config = new FormConfig(InvitationRequestType::class);
            $form = $helper->processForm($config, []);
        } catch (\Exception $e) {
            throw $helper->createBadRequestException($exceptionTranslator->transDomainThrowable($e), $e);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $email = $form->get('email')->getData();
            $portal = $form->get('portal')->getData();
            $portalUser = $portalUserDomain->getRepository()->createQueryBuilder('pu')
                ->join('pu.user', 'u')
                ->where('u.email = :email OR u.username = :email')
                ->andWhere('pu.portal = :portal')
                ->setParameter('email', $email)
                ->setParameter('portal', $portal)
                ->getQuery()
                ->getOneOrNullResult()
            ;

            if (null !== $portalUser) {
                return $helper->handleView($helper->createView($portalUser));
            }

            $user = $userDomain->getRepository()->createQueryBuilder('u')
                ->where('u.email = :email OR u.username = :email')
                ->setParameter('email', $email)
                ->getQuery()
                ->getOneOrNullResult()
            ;

            if (null !== $user) {
                /** @var PortalUserInterface $portalUser */
                $portalUser = $portalUserDomain->newInstance();
                $portalUser->setPortal($portal);
                $portalUser->setUser($user);

                if ($portalUser instanceof EnableInterface) {
                    $portalUser->setEnabled(true);
                }

                $res = $portalUserDomain->create($portalUser);

                if (!$res->isValid()) {
                    return $helper->handleView($helper->createView(
                        $helper->mergeAllErrors($res),
                        Response::HTTP_BAD_REQUEST
                    ));
                }

                return $helper->handleView($helper->createView($portalUser));
            }

            throw $helper->createNotFoundException();
        }

        return $helper->handleView($helper->createViewFormErrors($form));
    }

    /**
     * Create a user for the portal.
     *
     * @Route("/portal_users/create", methods={"POST"})
     * @Security("is_granted('perm:create', 'App\\Entity\\PortalUser')")
     */
    public function create(
        ControllerHelper $helper,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        if (class_exists(ScopeVote::class)) {
            $helper->denyAccessUnlessGranted(new ScopeVote('meta/portal_user'));
        }

        return $helper->create(
            Create::build(
                CreatePortalUserType::class,
                PortalUserInterface::class
            )->addListener(static function (PostSubmitEvent $event) use ($passwordHasher): void {
                /** @var PortalUserInterface $data */
                $data = $event->getData();

                /** @var PasswordAuthenticatedUserInterface|UserInterface $user */
                $user = $data->getUser();

                if ($event->getForm()->isValid()) {
                    $user->setPassword(
                        $passwordHasher->hashPassword(
                            $user,
                            $event->getForm()->get('user')->get('password')->getData()
                        )
                    );
                }
            }, FormPostSubmitListenerInterface::class)
        );
    }

    /**
     * Update a user for the portal.
     *
     * @Entity(
     *     "id",
     *     class="App:PortalUser",
     *     expr="repository.findPortalUserById(id)"
     * )
     * @Route("/portal_users/{id}/user", methods={"PATCH"})
     * @Security("is_granted('perm:update', id)")
     */
    public function updateUser(
        ControllerHelper $helper,
        MetadataManagerInterface $metadataManager,
        PortalUserInterface $id
    ): Response {
        if (class_exists(ScopeVote::class)) {
            $helper->denyAccessUnlessGranted(new ScopeVote('meta/portal_user'));
        }

        $meta = $metadataManager->get(UserInterface::class);

        if (null === $formType = $meta->getFormType()) {
            throw new InvalidArgumentException(sprintf(
                'The metadata form type of the "%s" class is required to edit the user info of portal user',
                $meta->getClass()
            ));
        }

        return $helper->update(Update::build(
            $formType,
            $id->getUser()
        ));
    }

    /**
     * Change the password of a portal user.
     *
     * @Entity(
     *     "id",
     *     class="App:PortalUser",
     *     expr="repository.findPortalUserById(id)"
     * )
     * @Route("/portal_users/{id}/change-password", methods={"PATCH"})
     * @Security("is_granted('perm:update', id)")
     */
    public function changePassword(
        ControllerHelper $helper,
        ChangePasswordHelper $changePasswordHelper,
        PortalUserInterface $id
    ): Response {
        if (class_exists(ScopeVote::class)) {
            $helper->denyAccessUnlessGranted(new ScopeVote('meta/portal_user'));
        }

        $user = $id->getUser();

        if (!$user instanceof ProfileableInterface || !$user instanceof PasswordAuthenticatedUserInterface) {
            throw $helper->createNotFoundException();
        }

        return $changePasswordHelper->process($user, false);
    }

    /**
     * Upload a user image for the portal user.
     *
     * @Entity(
     *     "id",
     *     class="App:PortalUser",
     *     expr="repository.findPortalUserById(id)"
     * )
     * @Route("/portal_users/{id}/user/upload", methods={"POST"})
     * @Security("is_granted('perm:update', id)")
     */
    public function uploadImage(
        ControllerHelper $helper,
        ContentManagerInterface $contentManager,
        PortalUserInterface $id
    ): Response {
        if (class_exists(ScopeVote::class)) {
            $helper->denyAccessUnlessGranted(new ScopeVote('meta/portal_user'));
        }

        $user = $id->getUser();

        if (!$user instanceof ProfileableInterface) {
            throw $helper->createNotFoundException();
        }

        return $contentManager->upload('user_image', $user);
    }
}
