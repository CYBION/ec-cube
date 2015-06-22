<?php
/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) 2000-2015 LOCKON CO.,LTD. All Rights Reserved.
 *
 * http://www.lockon.co.jp/
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */


namespace Eccube\Controller\Admin\Order;

use Eccube\Application;
use Eccube\Entity\MailHistory;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Request;

class MailController
{
    public function index(Application $app, Request $request, $id)
    {
        $Order = $app['eccube.repository.order']->find($id);

        if (is_null($Order)) {
            throw new NotFoundHttpException('order not found.');
        }

        $MailHistories = $app['eccube.repository.mail_history']->findBy(array('Order' => $id));

        $builder = $app['form.factory']->createBuilder('mail');
        $form = $builder->getForm();

        if ('POST' === $request->getMethod()) {

            $form->handleRequest($request);

            $mode = $request->get('mode');

            // テンプレート変更の場合は. バリデーション前に内容差し替え.
            if ($mode == 'change') {
                if ($form->get('template')->isValid()) {
                    /** @var $data \Eccube\Entity\MailTemplate */
                    $MailTemplate = $form->get('template')->getData();
                    $form = $builder->getForm();
                    $form->get('template')->setData($MailTemplate);
                    $form->get('subject')->setData($MailTemplate->getSubject());
                    $form->get('header')->setData($MailTemplate->getHeader());
                    $form->get('footer')->setData($MailTemplate->getFooter());
                }
            } else if ($form->isValid()) {
                switch ($mode) {
                    case 'confirm':
                        // フォームをFreezeして再生成.

                        $builder->setAttribute('freeze', true);
                        $builder->setAttribute('freeze_display_text', true);

                        $data = $form->getData();
                        $body = $this->createBody($app, $data['header'], $data['footer'], $Order);

                        $form = $builder->getForm();
                        $form->setData($data);

                        return $app->renderView('Order/mail_confirm.twig', array(
                            'form' => $form->createView(),
                            'body' => $body,
                            'Order' => $Order,
                        ));
                        break;
                    case 'complete':

                        $data = $form->getData();
                        $body = $this->createBody($app, $data['header'], $data['footer'], $Order);

                        // メール送信
                        $app['eccube.service.mail']->sendAdminOrderMail($Order, $data);

                        // 送信履歴を保存.
                        $MailTemplate = $form->get('template')->getData();
                        $MailHistory = new MailHistory();
                        $MailHistory
                            ->setSubject($data['subject'])
                            ->setMailBody($body)
                            ->setMailTemplate($MailTemplate)
                            ->setSendDate(new \DateTime())
                            ->setOrder($Order);
                        $app['orm.em']->persist($MailHistory);
                        $app['orm.em']->flush($MailHistory);


                        return $app->redirect($app->url('admin_order_mail_complete'));
                        break;
                    default:
                        break;
                }
            }
        }

        return $app->renderView('Order/mail.twig', array(
            'form' => $form->createView(),
            'Order' => $Order,
            'MailHistories' => $MailHistories
        ));
    }



    /**
     * Complete
     *
     * @param  Application $app
     * @return mixed
     */
    public function complete(Application $app)
    {
        return $app->renderView('Order/mail_complete.twig');
    }



    public function view(Application $app, $sendId)
    {
        $MailHistory = $app['eccube.repository.mail_history']->find($sendId);

        if (is_null($MailHistory)) {
            throw new HttpException('history not found.');
        }

        return $app['view']->render('Order/mail_view.twig', array(
            'subject' => $MailHistory->getSubject(),
            'body' => $MailHistory->getMailBody()
        ));
    }

    private function createBody($app, $header, $footer, $Order)
    {
        return $app->renderView('Mail/order.twig', array(
            'header' => $header,
            'footer' => $footer,
            'Order' => $Order,
        ));
    }
}
