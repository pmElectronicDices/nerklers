<?php

  namespace brandbox\base\import {

    use brandbox\brandbox\basic;
    use brandbox\component\basic_orm;
    use brandbox\component\i18n;
    use brandbox\component\http;
    use brandbox\brandbox\plugin;
    use brandbox\styleguide\skin;

    use brandbox\mam\upload;

    /**
     * @author Dirk Münker <muenker@konmedia.com>
     */
    class engine extends plugin\lib\engineAbstract {

      /**
       * @grantAccessRoute
       */
      public function done() {}

      /**
       * @return array
       */
      public function form() {
        $applicationConfig = $this->getApplicationConfig();
        $widget = new lib\widget\upload($applicationConfig);

        return ['form' => $widget->build()];
      }

      /**
       * @param lib\container $container
       * @param array $staticParams
       * @grantAccessPlugin
       */
      public function hookImportAfter(
        /** @noinspection PhpUnusedParameterInspection */
        lib\container $container,
        $staticParams = []
      ) {
      }

      /**
       * @param lib\container $container
       * @param array $staticParams
       *
       * @return array[]
       * @grantAccessPlugin
       */
      public function hookImportBefore(
        /** @noinspection PhpUnusedParameterInspection */
        lib\container $container,
        $staticParams = []
      ) {
        return [
          'container'    => $container,
          'staticParams' => $staticParams
        ];
      }

      /**
       * @param string $sourceParentTableIdentifier
       * @param int $targetParentContentID
       * @param int[] $targetChildContentIDs
       * @grantAccessPlugin
       */
      public function hookImportRelateAfter(
        /** @noinspection PhpUnusedParameterInspection */
        $sourceParentTableIdentifier,
        $targetParentContentID,
        $targetChildContentIDs
      ) {
      }

      /**
       * @param string $sourceParentTableIdentifier
       * @param int $targetParentContentID
       * @param int[] $targetChildContentIDs
       * @return array
       * @grantAccessPlugin
       */
      public function hookImportRelateBefore(
        /** @noinspection PhpUnusedParameterInspection */
        $sourceParentTableIdentifier,
        $targetParentContentID,
        $targetChildContentIDs
      ) {
        return ['result' => $targetChildContentIDs];
      }

      /**
       * @param string $tableIdentifier
       * @param int $sourceContentID
       * @param int $targetContentID
       * @param array $values
       * @return array
       * @grantAccessPlugin
       */
      public function hookImportRowSaveAfter(
        /** @noinspection PhpUnusedParameterInspection */
        $tableIdentifier,
        $sourceContentID,
        $targetContentID,
        $values
      ) {
        return ['result' => $values];
      }

      /**
       * @param int $tableIdentifier
       * @param int $sourceContentID
       * @param array $values
       * @return array
       * @grantAccessPlugin
       */
      public function hookImportRowSaveBefore(
        /** @noinspection PhpUnusedParameterInspection */
        $tableIdentifier,
        $sourceContentID,
        $values
      ) {
        return ['result' => $values];
      }

      /**
       * @param string $filename
       * @param array $staticParams
       * @return bool
       */
      public function import($filename, $staticParams = []) {
        $data = $this->fromFile($filename);

        $container = new lib\container();
        $container->setData($data['data']);
        $container->setChildren($data['children']);
        $container->setMap([]);

        $engineSelf = $this->getEngineSelf();

        $result = $engineSelf->hookImportBefore($container, $staticParams);
        $container = $result['serve']['container'];
        $staticParams = $result['serve']['staticParams'];

        $this->saveData($container, $staticParams);
        $this->saveRelations($container);

        $engineSelf->hookImportAfter($container, $staticParams);

        return true;
      }

      /**
       * @plugin   product/base
       * @priority 900
       */
      public function navigationBaseEntry() {
        $entry = (new skin\lib\navigation\secondary(self::class))
          ->setApplicationConfig($this->getApplicationConfig())
          ->to('form')
          ->icon('upload')
          ->label('Name')
        ;

        return ['entry' => [30 => $entry]];
      }

      /**
       * @param array $params
       *
       * @return http\respond\json
       */
      public function upload($params) {
        $engineUpload = $this->getAppFactory(upload\engine::class);
        $validMimeTypes = [
          'text/plain',
          'application/json'
        ];
        $path = $engineUpload->getTempPathToDomain('temp/import', 'base/ui');
        $file = $engineUpload->upload(
          'base-import',
          $path,
          $validMimeTypes,
          $params,
          true
        );

        return new http\respond\json([
          'file' => $file,
          'basename' => basename($file),
          'error' => i18n\translator::__('mam/upload', 'Upload fehlgeschlagen', [implode(', ', $validMimeTypes)])
        ]);
      }

      /**
       * @plugin component/common
       * @grantAccessPlugin
       * @priority 5000
       */
      public function requestGc() {
        $this
          ->getAppFactory(lib\execute\gc::class)
          ->execute()
        ;
      }

      /**
       * @param $filename
       * @return array
       */
      private function fromFile($filename) {
        $json = file_get_contents(APP_ROOT.$filename);
        $data = json_decode($json, true);

        return $data;
      }

      /**
       * @param string $parentTableIdentifier
       * @param string $childTableIdentifier
       *
       * @return basic_orm\bridge\bridgeInterface
       */
      private function getBridge($parentTableIdentifier, $childTableIdentifier) {
        $ormTable = basic_orm\sql\ormTable::get();
        $parentClassNames = $ormTable->getClassNames($parentTableIdentifier);
        $bridgeIdentifier = $parentTableIdentifier.ucfirst($childTableIdentifier);

        foreach($parentClassNames as $parentClassName):

          $parentTableFactory = $this->getTableByClass($parentClassName);
          $childTables = $parentTableFactory->getChildren();
          if(in_array($childTableIdentifier, $childTables)):

            $namespace = $parentTableFactory->getNamespace();
            $bridge = $namespace.'\\bridge\\'.$bridgeIdentifier;

            if(class_exists($bridge, true)):
              return $this->getBridgeByClass($bridge);
            endif;

          endif;
        endforeach;


        return null;
      }

      /**
       * @param string $className
       * @return basic_orm\bridge\bridgeInterface
       */
      private function getBridgeByClass($className) {
        $table = $this
          ->getManagerORM()
          ->getBridge($className)
        ;

        return $table;
      }

      /**
       * @return self
       */
      private function getEngineSelf() {
        $engine = $this->getAppLoose(self::class);
        $engine->setCallSendRequest(false);
        $engine->setUseFullChain(true);
        $engine->setParseHtml(false);

        return $engine;
      }

      /**
       * @param string $tableIdentifier
       *
       * @return \brandbox\component\basic_orm\table\tableInterface
       */
      private function getTable($tableIdentifier) {
        $sqlFactory = basic_orm\sql\ormTable::get();
        $className = $sqlFactory->getFirstClassName($tableIdentifier);

        return $this->getTableByClass($className);
      }

      /**
       * @param string $className
       *
       * @return basic_orm\table\tableInterface
       */
      private function getTableByClass($className) {
        $table = $this
          ->getManagerORM()
          ->getTable($className)
        ;

        return $table;
      }

      /**
       * @param lib\container $container
       * @param array $staticParams
       */
      private function saveData($container, $staticParams = []) {
        $engineSelf = $this->getEngineSelf();

        $clientID = $this->getApplicationClientID();
        $domainID = $this->getApplicationDomainID();

        foreach($container->getData() as $tableIdentifier => $datasets):

          $table = $this->getTable($tableIdentifier);

          foreach($datasets as $sourceContentID => $values):

            if(!empty($staticParams)):
              foreach($staticParams as $staticParamKey => $staticParamValue):
                if(isset($values[$staticParamKey])):
                  $values[$staticParamKey] = $staticParamValue;
                endif;
              endforeach;
            endif;

            if($table->isPanel()):
              $row = $table->getPanelRow($clientID, $domainID);
            else:
              $row = $table->getRow(0);
            endif;

            if($clientID != 0)
              $row->setClientID($clientID);
            if($domainID != 0)
              $row->setDomainID($domainID);

            $values = $engineSelf->hookImportRowSaveBefore(
              $tableIdentifier,
              $sourceContentID,
              $values
            )['serve']['result'];

            $row->mapFilledValues($values);
            $row = $row->save();
            $targetContentID = $row->getID();

            // Row nachträglich ändern
            $afterValues = $engineSelf->hookImportRowSaveAfter(
              $tableIdentifier,
              $sourceContentID,
              $targetContentID,
              $values
            )['serve']['result'];

            if($values !== $afterValues):
              $row = $table->getRow($targetContentID);
              $row->mapFilledValues($values);
              $row->save();
            endif;

            $container->appendToMap($tableIdentifier, $sourceContentID, $targetContentID);

          endforeach;
        endforeach;
      }

      /**
       * @param lib\container $container
       */
      private function saveRelations($container) {
        $engineSelf = $this->getEngineSelf();
        $children = $container->getChildren();
        $map = $container->getMap();

        foreach($children as $sourceParentTableIdentifier => $sourceParentContentIDs):
          foreach($sourceParentContentIDs as $sourceParentContentID => $sourceChildTableIdentifiers):

            $targetParentContentID = $map[$sourceParentTableIdentifier][$sourceParentContentID];

            foreach($sourceChildTableIdentifiers as $sourceChildTableIdentifier => $sourceChildContentIDs):
              $bridge = $this->getBridge($sourceParentTableIdentifier, $sourceChildTableIdentifier);

              $targetChildContentIDs = [];
              foreach($sourceChildContentIDs as $sourceChildContentID):
                $targetChildContentIDs[] = $map[$sourceChildTableIdentifier][$sourceChildContentID];
              endforeach;

              $targetChildContentIDs = $engineSelf->hookImportRelateBefore(
                $sourceParentTableIdentifier,
                $targetParentContentID,
                $targetChildContentIDs
              )['serve']['result'];

              $bridge->relate($targetParentContentID, $targetChildContentIDs);

              $engineSelf->hookImportRelateAfter(
                $sourceParentTableIdentifier,
                $targetParentContentID,
                $targetChildContentIDs
              );

            endforeach;
          endforeach;
        endforeach;

      }
    }
  }

?>