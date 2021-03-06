<?php

/*
 * This file is part of the Cocorico package.
 *
 * (c) Cocolabs SAS <contact@cocolabs.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cocorico\MessageBundle\Controller\Dashboard;

use FOS\MessageBundle\Model\ParticipantInterface;
use FOS\MessageBundle\Model\ThreadInterface;
use FOS\MessageBundle\Provider\ProviderInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\Form;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;


/**
 * Message controller.
 *
 * @Route("/message")
 */
class MessageController extends Controller
{

    /**
     * lists the available messages
     *
     * @Method("GET")
     * @Route("/{page}", name="cocorico_dashboard_message", requirements={"page" = "\d+"}, defaults={"page" = 1})
     *
     * @param Request $request
     * @param Integer $page
     * @return RedirectResponse
     */
    public function indexAction(Request $request, $page)
    {
        $user = $this->getUser();
        if (!is_object($user) || !$user instanceof ParticipantInterface) {
            throw new AccessDeniedException('This user does not have access to this section.');
        }

        $userType = $request->getSession()->get('profile', 'asker');
        $threadManager = $this->container->get('cocorico_message.thread_manager');
        $threads = $threadManager->getListingInboxThreads($user, $userType, $page);

        return $this->render(
            'CocoricoMessageBundle:Dashboard/Message:inbox.html.twig',
            array(
                'threads' => $threads,
                'pagination' => array(
                    'page' => $page,
                    'pages_count' => ceil($threads->count() / $threadManager->maxPerPage),
                    'route' => $request->get('_route'),
                    'route_params' => $request->query->all()
                ),
            )
        );

    }

    /**
     * Creates a new message thread.
     *
     * @Route("/{listingId}/new", name="cocorico_dashboard_message_new", requirements={"listingId" = "\d+"})
     * @param Request $request
     * @param         $listingId
     * @return RedirectResponse
     */
    public function newThreadAction(Request $request, $listingId)
    {
        $em = $this->container->get('doctrine')->getManager();

        /** @var Form $form */
        $form = $this->container->get('fos_message.new_thread_form.factory')->create();

        // get listing object
        $listing = $em->getRepository('CocoricoCoreBundle:Listing')->find($listingId);

        /** @var \Cocorico\MessageBundle\Entity\Thread $thread */
        $thread = $form->getData();
        $thread->setListing($listing);
        $thread->setSubject($listing->getTitle());
        $thread->setRecipient($listing->getUser());
        $form->setData($thread);

        $formHandler = $this->container->get('fos_message.new_thread_form.handler');
        if ($message = $formHandler->process($form)) {
            $this->container->get('cocorico_user.mailer.twig_swift')
                ->sendNotificationForNewMessageToUser($listing->getUser(), $message->getThread());

            return new RedirectResponse(
                $this->container->get('router')
                    ->generate(
                        'cocorico_dashboard_message_thread_view',
                        array(
                            'threadId' => $message->getThread()->getId()
                        )
                    )
            );
        } elseif ($form->isSubmitted() && !$form->isValid()) {
            $this->get('cocorico.helper.global')->addFormErrorMessagesToFlashBag(
                $form,
                $this->get('session')->getFlashBag()
            );

            return $this->redirect($request->headers->get('referer'));
        }

        return $this->container->get('templating')->renderResponse(
            'CocoricoMessageBundle:Dashboard/Message:newThread.html.twig',
            array(
                'form' => $form->createView(),
                'thread' => $form->getData(),
                'listing' => $listing
            )
        );
    }

    /**
     * Displays a thread, also allows to reply to it.
     * @Route("/conversation/{threadId}", name="cocorico_dashboard_message_thread_view", requirements={"threadId" = "\d+"})
     *
     * @param Request $request
     * @param         $threadId
     * @return RedirectResponse
     */
    public function threadAction(Request $request, $threadId)
    {
        /* @var $threadObj \Cocorico\MessageBundle\Entity\Thread */
        $threadObj = $this->getProvider()->getThread($threadId);
        /** @var Form $form */
        $form = $this->container->get('fos_message.reply_form.factory')->create($threadObj);

        $paramArr = $request->get($form->getName());

        $request->request->set($form->getName(), $paramArr);
        $formHandler = $this->container->get('fos_message.reply_form.handler');

        $selfUrl = $this->container->get('router')->generate(
            'cocorico_dashboard_message_thread_view',
            array('threadId' => $threadObj->getId())
        );

        if ($formHandler->process($form)) {
            $recipients = $threadObj->getOtherParticipants($this->getUser());
            $recipient = (count($recipients) > 0) ? $recipients[0] : $this->getUser();
            $this->container->get('cocorico_user.mailer.twig_swift')
                ->sendNotificationForNewMessageToUser($recipient, $threadObj);

            return new RedirectResponse($selfUrl);
        }

        $breadcrumbs = $this->get('cocorico.breadcrumbs_manager');
        $breadcrumbs->addPreItems($request);

        $breadcrumbs->addItem(
            $this->get('translator')->trans('Messages', array(), 'cocorico_breadcrumbs'),
            $this->get('router')->generate('cocorico_dashboard_message')
        );

        $users = $threadObj->getOtherParticipants($this->getUser());
        $user = (count($users) > 0) ? $users[0] : $this->getUser();

        $breadcrumbs->addItem(
            $this->get('translator')->trans(
                'Discussion with %name%',
                array('%name%' => $user->getName()),
                'cocorico_breadcrumbs'
            ),
            $selfUrl
        );

        return $this->container->get('templating')->renderResponse(
            'CocoricoMessageBundle:Dashboard/Message:thread.html.twig',
            array(
                'form' => $form->createView(),
                'thread' => $threadObj
            )
        );
    }

    /**
     * Deletes a thread
     * @Route("/delete/{threadId}", name="cocorico_dashboard_message_thread_delete", requirements={"threadId" = "\d+"})
     *
     * @param string $threadId the thread id
     *
     * @return RedirectResponse
     */
    public function deleteAction($threadId)
    {
        /** @var ThreadInterface $thread */
        $thread = $this->getProvider()->getThread($threadId);
        $this->container->get('fos_message.deleter')->markAsDeleted($thread);
        $this->container->get('fos_message.thread_manager')->saveThread($thread);

        return new RedirectResponse(
            $this->container->get('router')->generate('cocorico_dashboard_message')
        );
    }

    /**
     * Gets the provider service
     *
     * @return ProviderInterface
     */
    protected function getProvider()
    {
        return $this->container->get('fos_message.provider');
    }

    /**
     * Get number of unread messages for user
     *
     * @param Request $request
     *
     * @Route("/get-nb-unread-messages", name="cocorico_dashboard_message_nb_unread")
     *
     * @return Response
     */
    public function nbUnReadMessagesAction(Request $request)
    {
        $response = array('asker' => 0, 'offerer' => 0, 'total' => 0);
        if ($request->isXmlHttpRequest()) {
            $user = $this->getUser();
            $em = $this->container->get('doctrine')->getManager();
            $nbMessages = $em->getRepository('CocoricoMessageBundle:Message')->getNbUnreadMessage($user, true);

            $response['asker'] = ($nbMessages[0]['asker']) ? $nbMessages[0]['asker'] : 0;
            $response['offerer'] = $nbMessages[0]['offerer'] ? $nbMessages[0]['offerer'] : 0;
            $response['total'] = $response['asker'] + $response['offerer'];

        }

        return new Response(json_encode($response));
    }

}
