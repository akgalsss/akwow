<?php

class AdController extends Zend_Controller_Action
{

    private $user = null;

    public function init()
    {
        $auth = Zend_Auth::getInstance();
        if (!$auth->hasIdentity()) {
            //$this->_helper->redirector('index', 'auth');
        }

        $this->user = $auth->getIdentity();
        $vars = $this->getAllParams();

        if (($vars["action"] == "set-status") && ($this->user->role !== Application_Model_User::ADMIN)) {
            $this->_helper->redirector('index', 'index');
        }
    }

    public function indexAction()
    {
        $auth = Zend_Auth::getInstance();
        $item = new Application_Model_Ad();
        $owner = new Application_Model_Partner();
        $vars = $this->getAllParams();

        if (isset($vars["id"])) {
            $item->get($vars["id"]);
            $owner->getByUserId($item->owner);
            $this->view->ad = $item;
            $this->view->user = $owner;

            $neighborArr = $item->getNeighborhood();
            $this->view->nextAdUrl = !is_null($neighborArr["previous"])?$neighborArr["previous"]->createUrl():null;
            $this->view->prevAdUrl = !is_null($neighborArr["next"])?$neighborArr["next"]->createUrl():null;

            if ($auth->hasIdentity()) {
                $this->view->isFavorite = $item->checkFavorites($auth->getIdentity());
                $this->view->isFavoritesUrl = $item->getFavoritesUrl(null, "remove");
                $this->view->notFavoritesUrl = $item->getFavoritesUrl(null, "add");
            } else {
                $this->view->isFavorite = false;
                $this->view->isFavoritesUrl = $item->getFavoritesUrl();
                $this->view->notFavoritesUrl = $item->getFavoritesUrl();
            }
        } else {
            $this->redirect("/index/index");
        }
    }

    public function randomizeAction() {
        $item = new Application_Model_Ad();
        $item->randomizeAll();
    }

    public function newAction()
    {
        global $translate;
        $layout = Zend_Layout::getMvcInstance();
        $view = $layout->getView();

        $this->view->mainForm = new Application_Form_AdMain();
        $this->view->contactsForm = new Application_Form_AdContacts();
        $this->view->datesForm = new Application_Form_AdDates();
        $this->view->settingsForm = new Application_Form_AdSettings();
        $this->view->mediaForm = new Application_Form_AdMedia();

        $forms = array(
            "AdMain" => $this->view->mainForm,
            "AdContacts" => $this->view->contactsForm,
            "AdDates" => $this->view->datesForm,
            "AdSettings" => $this->view->settingsForm,
            "AdMedia" => $this->view->mediaForm
        );

        $formsOrder = array(
            'AdMain' => "dates",
            'AdDates' => "settings",
            'AdSettings' => "contacts",
            'AdContacts' => "media",
            'AdMedia' => "main"
        );

        $item = new Application_Model_Ad();
        $request = $this->getRequest();

        $partner = new Application_Model_Partner();
        $partner->getByUserId($this->user->id);
        $partnerData = $partner->toArray();
        unset($partnerData["id"]);
        $item->loadIfEmpty($partnerData);

        $geoVal = "1-0-0";
        if ($this->_getParam('geo'))
            $geoVal = $this->_getParam('geo');
        $this->view->settingsForm->prepareGeo($geoVal);
        $this->view->settingsForm->populate(array("geo" => $geoVal));

        if ($request->isPost()) {
            $formData = $request->getPost();
            if ($formData["id"])
                $item->get($formData["id"]);
            $form = $forms[$formData["form"]];
            // Geo value fix
            if ($formData["form"] == "AdSettings") {
                $geoArr = explode("-", $formData["geo"]);
                $formData["country"] = $geoArr[0]?$geoArr[0]:"1";
                $formData["region"] = $formData["country"] . "-" . (isset($geoArr[1])?$geoArr[1]:"0");
                $formData["district"] = $formData["region"] . "-" . (isset($geoArr[2])?$geoArr[2]:"0");
            }
            if ($form->isValid($formData)) {
                $mediaItemData = array();
                if ($formData["form"] == "AdMedia") {
                    $mediaItemData = $form->processData();
                }
                $itemData = $form->getValues();
                $itemData = array_merge($mediaItemData, $itemData);
                if ($formData["form"] == "AdSettings") {
                    $settingsData = $form->processData($formData);
                    $itemData = array_merge($settingsData, $itemData);
                }

                $itemData["owner"] = $this->user->id;
                $item->load($itemData);
                $id = $item->save();
                if ($id) {
                    $url = $this->_helper->url('edit', 'ad', null, array("id" => $item->id));
                    if ($formData["form"] != "AdMain")
                        $url .= '#main';
                    else
                        $url .= '#' . $formsOrder[$formData["form"]];
                    $this->redirect($url);
                    $view->successMessage = $translate->getAdapter()->translate("success") . " " . $translate->getAdapter()->translate("data_save_success");
                } else {
                    $view->errorMessage = $translate->getAdapter()->translate("error") . " " . $translate->getAdapter()->translate("data_save_error");
                }
            } else {
                $tabs = explode("Ad", $formData["form"]);
                $this->view->gotoTab = strtolower($tabs[1]);
                $view->errorMessage = $translate->getAdapter()->translate("error") . " " . $translate->getAdapter()->translate("data_save_error");
            }
        }

        $data = $item->toArray();
        foreach ($forms as $form) {
            $form->populate($data);
        }
        if (isset($formData["form"]))
            $forms[$formData["form"]]->populate($formData);
    }

    public function listAction () {
        global $translate;

        $params = null;
        $request = new Zend_Controller_Request_Http();
        if ($request->getCookie('category'))
            $params["category"] = $request->getCookie('category');
        if ($request->getCookie('geo'))
            $params["geo"] = $request->getCookie('geo');
        $ad = new Application_Model_Ad();
        $res = $ad->getList($params);
        $data = array();
        foreach ($res AS $val) {
            $data[] = $val->toListArray($this->user);
        }
        $res = array(
            "list" => $data,
            "options" => array(
                "days_left_text" => $translate->getAdapter()->translate("days_left")
            )
        );
        $this->_helper->json($res);
    }

    public function favoritesAction () {
        global $translate;
        $ad = new Application_Model_Ad();
        $res = $ad->getFavorites($this->user->favorites_ads);
        $data = array();
        foreach ($res AS $val) {
            $data[] = $val->toListArray($this->user);
        }
        $res = array(
            "list" => $data,
            "options" => array(
                "days_left_text" => $translate->getAdapter()->translate("days_left")
            )
        );
        $this->_helper->json($res);
    }

    public function setStatusAction () {
        global $translate;

        $vars = $this->getAllParams();
        $ad = new Application_Model_Ad();
        if ($ad->get($vars["id"])){
            eval("\$ad->status = Application_Model_DbTable_Ad::STATUS_" . $vars["status"] . ";");
            $ad->save();
        }
        switch ($vars["status"]) {
            case Application_Model_DbTable_Ad::STATUS_ACTIVE :
                $dest = "ready";
                break;

            case Application_Model_DbTable_Ad::STATUS_ARCHIVE :
                $dest = "active";
                break;
        }
        $this->_helper->redirector($dest, 'admin');
    }

    public function editAction()
    {
        global $translate;
        $layout = Zend_Layout::getMvcInstance();
        $view = $layout->getView();

        $vars = $this->getAllParams();

        $item = new Application_Model_Ad();
        $formData = $this->getAllParams();
        if (isset($formData["id"]))
            $item->get($formData["id"]);
        else
            return false;

        $isReady = $item->isValid();

        $partner = new Application_Model_Partner();
        $partner->getByUserId($this->user->id);
        $partnerData = $partner->toArray();
        unset($partnerData["id"]);
        $item->loadIfEmpty($partnerData);

        $this->view->mainForm = new Application_Form_AdMain(array("isReady" => $isReady));
        $this->view->contactsForm = new Application_Form_AdContacts(array("isReady" => $isReady));
        $this->view->datesForm = new Application_Form_AdDates(array("isReady" => $isReady));
        $this->view->settingsForm = new Application_Form_AdSettings(array("isReady" => $isReady));
        $this->view->mediaForm = new Application_Form_AdMedia(array("isReady" => $isReady));

        $forms = array(
            "AdMain" => $this->view->mainForm,
            "AdContacts" => $this->view->contactsForm,
            "AdDates" => $this->view->datesForm,
            "AdSettings" => $this->view->settingsForm,
            "AdMedia" => $this->view->mediaForm
        );

        $formsOrder = array(
            'AdMain' => "dates",
            'AdDates' => "settings",
            'AdSettings' => "contacts",
            'AdContacts' => "media",
            'AdMedia' => "main"
        );

        $this->view->image = $item->image;
        $this->view->banner = $item->banner;
        $request = $this->getRequest();
        $geoVal = $item->geo;
        if ($this->_getParam('geo'))
            $geoVal = $this->_getParam('geo');
        if (empty($geoVal)) {
            $geoVal = "1-0-0";
        }
        $this->view->settingsForm->prepareGeo($geoVal);

        $this->view->ad = $item;
        if ($request->isPost()) {
            $formData = $this->getAllParams();
            if (isset($formData["district"])) {
                if (sizeof(explode(".", $formData["district"])) == 2)
                    $formData["district"] .= ".0";
            }
            $form = $forms[$formData["form"]];
            // Geo value fix
            if ($formData["form"] == "AdSettings") {
                $geoArr = explode("-", $formData["geo"]);
                $formData["country"] = $geoArr[0]?$geoArr[0]:"1";
                $formData["region"] = $formData["country"] . "-" . (isset($geoArr[1])?$geoArr[1]:"0");
                $formData["district"] = $formData["region"] . "-" . (isset($geoArr[2])?$geoArr[2]:"0");
            }
            if ($form->isValid($formData)) {
                $mediaItemData = array();
                if ($formData["form"] == "AdMedia") {
                    $mediaItemData = $form->processData();
                }
                $itemData = $form->getValues();
                $itemData = array_merge($mediaItemData, $itemData);

                if ($formData["form"] == "AdSettings") {
                    $settingsData = $form->processData($formData);
                    $itemData = array_merge($settingsData, $itemData);
                }

                if ($isReady) {
                    $item->status = Application_Model_DbTable_Ad::STATUS_READY;
                }

                $itemData["owner"] = $this->user->id;
                $item->load($itemData);
                $item->save();
                if ($item->id) {
                    if ($isReady) {
                        if ($this->user->role == Application_Model_User::ADMIN)
                            $this->_helper->redirector('ready', 'admin');
                        else
                            $this->_helper->redirector('ready', 'ad');
                    }
                    $url = $this->_helper->url->url(array(
                        'controller' => 'ad',
                        'action' => 'edit'
                    ));
                    $url .= '#' . $formsOrder[$vars["form"]];
                    $this->_helper->redirector->gotoUrl($url);
                    $view->successMessage = $translate->getAdapter()->translate("success") . " " . $translate->getAdapter()->translate("data_save_success");
                } else {
                    $view->errorMessage = $translate->getAdapter()->translate("error") . " " . $translate->getAdapter()->translate("data_save_error");
                }
            } else {
                $tabs = explode("Ad", $formData["form"]);
                $this->view->gotoTab = strtolower($tabs[1]);
                $view->errorMessage = $translate->getAdapter()->translate("error") . " " . $translate->getAdapter()->translate("data_save_error");
            }
        }

        $data = $item->toArray();
        foreach ($forms as $key => $form) {
            $form->populate($data);
        }

        if (isset($formData["form"]))
            $forms[$formData["form"]]->populate($formData);
    }

    public function _createEditLink($id, $name)
    {
        global $translate;
        if (empty($name))
            $name = $translate->getAdapter()->translate("empty_name");
        return '<a href="/ad/edit/id/' . $id . '">' . $name . '</a>';
    }

    public function _paidText($val, $id)
    {
        global $translate;
        if ($val)
            $text = $translate->getAdapter()->translate("yes");
        else
            $text = '<a href="/payment/prepare/item_id/' . $id .'">' . $translate->getAdapter()->translate("make_payment") . '</a>';
        return $text;
    }

    public function _daysLeft($end_dt, $public_dt)
    {
        if (strtotime($public_dt) < time())
            return ceil((strtotime($end_dt) - time()) / 86400) + 1;
        else
            return ceil((strtotime($end_dt) - strtotime($public_dt)) / 86400) + 1;
    }

    public function _createPreviewLink($id, $name)
    {
        global $translate;
        if (empty($name))
            $name = $translate->getAdapter()->translate("empty_name");
        return '<a href="/ad/index/id/' . $id . '">' . $name . '</a>';
    }

    public function activeAction()
    {
        global $translate;

        $grid = Bvb_Grid::factory('Table');
        $source = new Bvb_Grid_Source_Zend_Table(new Application_Model_DbTable_Ad());
        $grid->setSource($source);
        $grid->getSelect()->where("status = ? AND end_dt > NOW() AND owner = " . $this->user->id, Application_Model_DbTable_Ad::STATUS_ACTIVE);
        $grid->setGridColumns(array("name", "days_left", "public_dt", "start_dt", "end_dt"));
        $grid->updateColumn('name',array(
            "title" =>  $translate->getAdapter()->translate("name"),
            'callback'=>array(
                'function'=>array($this, '_createEditLink'),
                'params'=>array('{{id}}', "{{name}}")
            )
        ));
        $grid->updateColumn('public_dt',array(
            "title" =>  $translate->getAdapter()->translate("public_date"),
        ));
        $grid->updateColumn('start_dt',array(
            "title" =>  $translate->getAdapter()->translate("start_date"),
        ));
        $grid->updateColumn('end_dt',array(
            "title" =>  $translate->getAdapter()->translate("end_date"),
        ));

        $grid->addExtraColumn(array(
            "name" => "days_left",
            "position" => "right",
            "title" =>  $translate->getAdapter()->translate("days_left"),
            'callback'=>array(
                'function'=>array($this, '_daysLeft'),
                'params'=>array('{{end_dt}}', '{{public_dt}}')
            ))
        );
        $grid->setTemplateParams(array("cssClass" => array("table" => "table table-bordered table-striped")));
        $grid->setNoFilters(true);
        $grid->setExport(array());
        $grid->setImagesUrl('/img/');
        $this->view->grid = $grid;
    }

    public function noactiveAction()
    {
        global $translate;

        $grid = Bvb_Grid::factory('Table');
        $source = new Bvb_Grid_Source_Zend_Table(new Application_Model_DbTable_Ad());
        $grid->setSource($source);
        $grid->getSelect()->where("status IN (?) AND owner = " . $this->user->id, array(Application_Model_DbTable_Ad::STATUS_DRAFT));
        $grid->setGridColumns(array("name", "public_dt", "start_dt", "end_dt"));
        $grid->updateColumn('name',array(
            "title" =>  $translate->getAdapter()->translate("name"),
            'callback'=>array(
                'function'=>array($this, '_createEditLink'),
                'params'=>array('{{id}}', "{{name}}")
            )
        ));
        $grid->updateColumn('public_dt',array(
            "title" =>  $translate->getAdapter()->translate("public_date"),
        ));
        $grid->updateColumn('start_dt',array(
            "title" =>  $translate->getAdapter()->translate("start_date"),
        ));
        $grid->updateColumn('end_dt',array(
            "title" =>  $translate->getAdapter()->translate("end_date"),
        ));
        $grid->setTemplateParams(array("cssClass" => array("table" => "table table-bordered table-striped")));
        $grid->setNoFilters(true);
        $grid->setExport(array());
        $grid->setImagesUrl('/img/');
        $this->view->grid = $grid;
    }

    public function readyAction()
    {
        global $translate;

        $grid = Bvb_Grid::factory('Table');
        $source = new Bvb_Grid_Source_Zend_Table(new Application_Model_DbTable_Ad());
        $grid->setSource($source);
        $grid->getSelect()->where("status IN (?) AND owner = " . $this->user->id, array(Application_Model_DbTable_Ad::STATUS_READY));
        $grid->setGridColumns(array("name", 'paid', "public_dt", "start_dt", "end_dt"));
        $grid->updateColumn('name',array(
            "title" =>  $translate->getAdapter()->translate("name"),
            'callback'=>array(
                'function'=>array($this, '_createEditLink'),
                'params'=>array('{{id}}', "{{name}}")
            )
        ));
        $grid->updateColumn('public_dt',array(
            "title" =>  $translate->getAdapter()->translate("public_date"),
        ));
        $grid->updateColumn('start_dt',array(
            "title" =>  $translate->getAdapter()->translate("start_date"),
        ));
        $grid->updateColumn('end_dt',array(
            "title" =>  $translate->getAdapter()->translate("end_date"),
        ));
        $grid->updateColumn('paid',array(
            "title" =>  $translate->getAdapter()->translate("paid"),
            'callback'=>array(
                'function'=>array($this, '_paidText'),
                'params'=>array('{{paid}}','{{id}}')
            )
        ));
        $grid->setTemplateParams(array("cssClass" => array("table" => "table table-bordered table-striped")));
        $grid->setNoFilters(true);
        $grid->setExport(array());
        $grid->setImagesUrl('/img/');
        $this->view->grid = $grid;
    }

    public function archiveAction()
    {
        global $translate;

        $grid = Bvb_Grid::factory('Table');
        $source = new Bvb_Grid_Source_Zend_Table(new Application_Model_DbTable_Ad());
        $grid->setSource($source);
        $grid->getSelect()->where("(status = ? OR end_dt < NOW()) AND owner = " . $this->user->id, Application_Model_DbTable_Ad::STATUS_ARCHIVE);
        $grid->setGridColumns(array("name", "public_dt", "start_dt", "end_dt"));
        $grid->updateColumn('name',array(
            "title" =>  $translate->getAdapter()->translate("name"),
            'callback'=>array(
                'function'=>array($this, '_createEditLink'),
                'params'=>array('{{id}}', "{{name}}")
            )
        ));
        $grid->updateColumn('public_dt',array(
            "title" =>  $translate->getAdapter()->translate("public_date"),
        ));
        $grid->updateColumn('start_dt',array(
            "title" =>  $translate->getAdapter()->translate("start_date"),
        ));
        $grid->updateColumn('end_dt',array(
            "title" =>  $translate->getAdapter()->translate("end_date"),
        ));
        $grid->setTemplateParams(array("cssClass" => array("table" => "table table-bordered table-striped")));
        $grid->setNoFilters(true);
        $grid->setExport(array());
        $grid->setImagesUrl('/img/');
        $this->view->grid = $grid;
    }

    public function getfullinfoAction()
    {
        $vars = $this->getAllParams();
        $item = new Application_Model_Ad();
        $item->get((int)$vars["id"]);
        echo nl2br($item->full_description);
        exit();
    }
}