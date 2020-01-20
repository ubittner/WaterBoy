<?php

/*
 * @module      WaterBoy
 *
 * @prefix      WBOY
 *
 * @file        module.php
 *
 * @author      Ulrich Bittner
 * @copyright   (c) 2020
 * @license    	CC BY-NC-SA 4.0
 *              https://creativecommons.org/licenses/by-nc-sa/4.0/
 *
 * @version     1.00-1
 * @date        2020-01-20, 18:00, 1579539600
 * @review      2020-01-20, 18:00, 1579539600
 *
 * @see         https://github.com/ubittner/Alarmsirene/
 *
 * @guids       Library
 *              {271B17F5-54C2-8940-8A2B-6E0762DAD94C}
 *
 *              WaterBoy
 *             	{A15A568A-E75E-6338-F0F9-6284EDE28B01}
 */

// Declare
declare(strict_types=1);

// Include
include_once __DIR__ . '/helper/autoload.php';

class WaterBoy extends IPSModule
{
    // Helper
    use WBOY_waterControl;

    public function Create()
    {
        // Never delete this line!
        parent::Create();

        // Register properties
        $this->RegisterProperties();

        // Create profiles
        $this->CreateProfiles();

        // Register variables
        $this->RegisterVariables();

        // Register timers
        $this->RegisterTimers();
    }

    public function ApplyChanges()
    {
        // Wait until IP-Symcon is started
        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        // Never delete this line!
        parent::ApplyChanges();

        // Check runlevel
        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }

        // Set options
        $this->SetOptions();

        // Validate configuration
        $this->ValidateConfiguration();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        // Send debug
        $this->SendDebug('MessageSink', 'Message from SenderID ' . $SenderID . ' with Message ' . $Message . "\r\n Data: " . print_r($Data, true), 0);
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->KernelReady();
                break;

        }
    }

    protected function KernelReady()
    {
        $this->ApplyChanges();
    }

    public function Destroy()
    {
        // Never delete this line!
        parent::Destroy();

        // Delete profiles
        $this->DeleteProfiles();
    }

    //#################### Request Action

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'SolenoidValve':
                $this->ToggleSolenoidValve($Value);
                break;

            case 'CycleTime':
                $this->SetCycleTime($Value);
                break;

        }
    }

    //#################### Private

    private function RegisterProperties(): void
    {
        // Visibility
        $this->RegisterPropertyBoolean('EnableSolenoidValve', true);
        $this->RegisterPropertyBoolean('EnableCycleTime', true);

        // Valve
        $this->RegisterPropertyBoolean('MaintenanceMode', false);
        $this->RegisterPropertyInteger('SolenoidValve', 0);
    }

    private function CreateProfiles(): void
    {
        // Solenoid valve
        $profile = 'WBOY.' . $this->InstanceID . '.SolenoidValve';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 0);
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'Zu', 'Execute', -1);
        IPS_SetVariableProfileAssociation($profile, 1, 'Auf', 'Tap', 0x00FF00);

        // Cycle time
        $profile = 'WBOY.' . $this->InstanceID . '.CycleTime';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1);
        }
        IPS_SetVariableProfileIcon($profile, 'Clock');
        IPS_SetVariableProfileValues($profile, 1, 60, 1);
        IPS_SetVariableProfileDigits($profile, 1);
        IPS_SetVariableProfileText($profile, '', ' s');
    }

    private function DeleteProfiles(): void
    {
        $profiles = ['SolenoidValve', 'CycleTime'];
        if (!empty($profiles)) {
            foreach ($profiles as $profile) {
                $profileName = 'WBOY.' . $this->InstanceID . '.' . $profile;
                if (IPS_VariableProfileExists($profileName)) {
                    IPS_DeleteVariableProfile($profileName);
                }
            }
        }
    }

    private function RegisterVariables(): void
    {
        // Solenoid valve
        $profile = 'WBOY.' . $this->InstanceID . '.SolenoidValve';
        $this->RegisterVariableBoolean('SolenoidValve', 'Magnetventil', $profile, 1);
        $this->EnableAction('SolenoidValve');

        // Cycle time
        $profile = 'WBOY.' . $this->InstanceID . '.CycleTime';
        $this->RegisterVariableInteger('CycleTime', 'Durchlaufzeit', $profile, 2);
        $this->EnableAction('CycleTime');
    }

    private function SetOptions(): void
    {
        // Solenoid valve
        $id = $this->GetIDForIdent('SolenoidValve');
        $use = $this->ReadPropertyBoolean('EnableSolenoidValve');
        IPS_SetHidden($id, !$use);

        // Cycle time
        $id = $this->GetIDForIdent('CycleTime');
        $use = $this->ReadPropertyBoolean('EnableCycleTime');
        IPS_SetHidden($id, !$use);
    }

    private function RegisterTimers(): void
    {
        $this->RegisterTimer('CloseSolenoidValve', 0, 'WBOY_CloseSolenoidValve(' . $this->InstanceID . ');');
    }

    private function DisableTimers(): void
    {
        $this->SetTimerInterval('CloseSolenoidValve', 0);
    }

    private function ValidateConfiguration(): void
    {
        $status = 102;
        if (!$this->CheckSolenoidValve()) {
            $status = 200;
        }
        $this->SetStatus($status);
        if ($status == 102) {
            $this->CloseSolenoidValve();
        }
    }
}