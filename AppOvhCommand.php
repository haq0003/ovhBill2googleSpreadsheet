<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use \Ovh\Api;
use Google_Client;
use Google_Service_Gmail;
use Google_Service_Drive;
use Google_Service_Drive_DriveFile;
use Google_Service_Sheets;
use Google_Service_Sheets_ValueRange;
use Google_Service_Sheets_BatchUpdateValuesRequest;

class AppOvhCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('app:fetchBillingOvh')
            ->setDescription('...')
            ->addArgument('argument', InputArgument::OPTIONAL, 'Argument description')
            ->addOption('option', null, InputOption::VALUE_NONE, 'Option description');

        // php bin/console app:fetchBillingOvh
        // composer require ovh/ovh
        // https://api.ovh.com/createToken/index.cgi?GET=/me



    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        set_time_limit(1500);
        $argument = $input->getArgument('argument');

        $endpoint = 'ovh-eu';

        $ovh = new Api("XXXXXX",
            "XXXXXX",
            $endpoint,
            "XXXXXX");

        $from = (new \DateTime('-1 week', new \DateTimeZone("Europe/Paris")))->format('Y-m-d\TH:i:s.u');
        $to = (new \DateTime('NOW', new \DateTimeZone("Europe/Paris")))->format('Y-m-d\TH:i:s.u');

        $bills = $ovh->get("/me/bill",
            [
                'date.from' => $from,
                'date.to' => $to
            ]);

        // $bills = $ovh->get("/me/bill");

        $HEADER = [
            "domain","billId","billDetailId","description","totalPriceValue","priceWithoutTaxValue","billDate",
            "periodStart", "periodEnd", "paymentType", "paymentDate", "Facture", "SHA1"
        ];
        $DATA = [$HEADER];

        $table = new Table($output);
        $table
            ->setHeaders($HEADER);

        foreach ($bills as $bill) {

            // Get this object properties bill
            $propertiesBill = $ovh->get("/me/bill/$bill");

            // Get this object properties paiement
            $propertiesPayement = $ovh->get("/me/bill/$bill/payment");
            // Give access to all entries of the bill
            $entries = $ovh->get('/me/bill/' . $bill . '/details');

            foreach ($entries as $billDetailId) {
                $row = $ovh->get("/me/bill/$bill/details/$billDetailId");
                $ONE_ROW = [];
                $ONE_ROW[] = (string) $row["domain"];
                $ONE_ROW[] = (string) $propertiesBill["billId"];
                $ONE_ROW[] = (string) $row["billDetailId"];
                $ONE_ROW[] = (string) $row["description"];
                $ONE_ROW[] = (string) $row["totalPrice"]["value"];
                $ONE_ROW[] = (string) $propertiesBill["priceWithoutTax"]["value"];
                $ONE_ROW[] = (string) $propertiesBill["date"];
                $ONE_ROW[] = (string) $row["periodStart"];
                $ONE_ROW[] = (string) $row["periodEnd"];
                $ONE_ROW[] = (string) $propertiesPayement["paymentType"];
                $ONE_ROW[] = (string) $propertiesPayement["paymentDate"];
                $ONE_ROW[] = (string) $propertiesBill["url"];
                $ONE_ROW[] = sha1(implode("",$ONE_ROW));
                $DATA[] = $ONE_ROW;
                $table->addRows([$ONE_ROW]);
            }
        }

        $table->render();

        $this->saveOnDrive($DATA);


        $output->writeln("\nEND");
    }

    public function saveOnDrive($data)
    {
        $appName = 'XXXXXXXX';
        $credentialsPath = __DIR__ . '/.credentials/XXXXXXX-php-2.json';
        $clientSecretPath = __DIR__ . '/client_secret_XXXXXX.apps.googleusercontent.com.json';
        $scopes = implode(' ', array(
                Google_Service_Drive::DRIVE
            )
        );

        $google = new Google_Client();
        $google->setApplicationName($appName);
        $google->setScopes($scopes);
        $google->setAuthConfig($clientSecretPath);
        $google->setAccessType('offline');

        /******************** CONNEXION ***************/

        // Load previously authorized credentials from a file.
        if (file_exists($credentialsPath)) {
            $accessToken = json_decode(file_get_contents($credentialsPath), true);
        } else {
            // Request authorization from the user.
            $authUrl = $google->createAuthUrl();
            printf("Open the following link in your browser:\n%s\n", $authUrl);
            print 'Enter verification code: ';
            $authCode = trim(fgets(STDIN));
            // Exchange authorization code for an access token.
            $accessToken = $google->fetchAccessTokenWithAuthCode($authCode);

            // Store the credentials to disk.
            if (!file_exists(dirname($credentialsPath))) {
                mkdir(dirname($credentialsPath), 0700, true);
            }
            file_put_contents($credentialsPath, json_encode($accessToken));
            printf("Credentials saved to %s\n", $credentialsPath);
        }
        $google->setAccessToken($accessToken);

        // Refresh the token if it's expired.
        if ($google->isAccessTokenExpired()) {
            $google->fetchAccessTokenWithRefreshToken($google->getRefreshToken());
            file_put_contents($credentialsPath, json_encode($google->getAccessToken()));
        }

        $service = new Google_Service_Drive($google);

        $spreadsheetId = 'XXXXXX';

        $sheets = new Google_Service_Sheets($google);

        $response = $sheets->spreadsheets_values->get($spreadsheetId, ["SHEET1!M1:M999"]);
        $listSHA = $response->getValues();


        /*******************DON'T SEND ALREADY SENT ***/
		
        if($listSHA) {
            $idSHA = 0;
            foreach ($data[0] as $_j => $_c) {
                if ($_c == "SHA1") {
                    $idSHA = $_j;
                }
            }

            if (!$idSHA) {
                dump("Colonne SHA1 not found !!!");
            }

            foreach ($listSHA as $_sha) {

                foreach ($data as $_i => $_r) {
                    if ($_r[$idSHA] == $_sha[0]) {
                        // Remove row already present in speadsheet
                        unset($data[$_i]);
                        break;
                    }

                }
            }
        }

        // reorder id after unset
        $data = array_values($data);

        /**********************************************/

        if(!$data){
            dump("Table is empty !!!");
            return;
        }

        $requestBody = new Google_Service_Sheets_BatchUpdateValuesRequest();

        if($listSHA){
            $nbRow = count($listSHA)+1;
        }
        else{
            $nbRow = 1;
        }

        $requestBody->setValueInputOption('RAW');
        $requestBody->setData([
            'range' => "'SHEET1'!A$nbRow:M",
            'majorDimension' => 'ROWS',
            'values' => $data
        ]);

        $response = $sheets->spreadsheets_values->batchUpdate($spreadsheetId, $requestBody);

        dump($response);
    }


}
