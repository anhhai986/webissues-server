<?php
/**************************************************************************
* This file is part of the WebIssues Server program
* Copyright (C) 2006 Michał Męciński
* Copyright (C) 2007-2011 WebIssues Team
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
**************************************************************************/

require_once( '../../system/bootstrap.inc.php' );

class Client_Issues_Comment extends System_Web_Component
{
    private $comment = null;
    private $issue = null;
    private $oldText = null;

    protected function __construct()
    {
        parent::__construct();
    }

    protected function execute()
    {
        $this->view->setDecoratorClass( 'Common_FixedBlock' );

        switch ( $this->request->getScriptBaseName() ) {
            case 'addcomment':
                $issueManager = new System_Api_IssueManager();
                $issueId = (int)$this->request->getQueryString( 'issue' );
                $this->issue = $issueManager->getIssue( $issueId );

                $this->oldText = '';

                $this->issueName = $this->issue[ 'issue_name' ];
                $this->commentId = '';

                $this->view->setSlot( 'page_title', $this->tr( 'Add Comment' ) );
                break;

            case 'editcomment':
                $issueManager = new System_Api_IssueManager();
                $commentId = (int)$this->request->getQueryString( 'id' );
                $this->comment = $issueManager->getComment( $commentId, System_Api_IssueManager::RequireAdministratorOrOwner );
                $this->issue = $issueManager->getIssue( $this->comment[ 'issue_id' ] );

                $this->oldText = $this->comment[ 'comment_text' ];

                $this->issueName = '';
                $this->commentId = '#' . $this->comment[ 'comment_id' ];

                $this->view->setSlot( 'page_title', $this->tr( 'Edit Comment' ) );
                break;

            default:
                throw new System_Core_Exception( 'Invalid URL' );
        }

        $breadcrumbs = new System_Web_Breadcrumbs( $this );
        $breadcrumbs->initialize( System_Web_Breadcrumbs::Issue, $this->issue );

        $this->form = new System_Web_Form( 'issues', $this );
        $this->form->addField( 'commentText', $this->oldText );

        $serverManager = new System_Api_ServerManager();
        $this->form->addTextRule( 'commentText', $serverManager->getSetting( 'comment_max_length' ), System_Api_Parser::MultiLine );

        if ( $this->form->loadForm() ) {
            if ( $this->form->isSubmittedWith( 'cancel' ) )
                $this->response->redirect( $breadcrumbs->getParentUrl() );

            $this->form->validate();

            if ( $this->form->isSubmittedWith( 'ok' ) && !$this->form->hasErrors() ) {
                $this->submit();
                $this->response->redirect( $breadcrumbs->getParentUrl() );
            }
        }
    }

    private function submit()
    {
        $issueManager = new System_Api_IssueManager();
        if ( $this->comment == null )
            $issueManager->addComment( $this->issue, $this->commentText );
        else
            $issueManager->editComment( $this->comment, $this->commentText );
    }
}
