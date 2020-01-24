<?php

// Declare
declare(strict_types=1);

trait WBOY_valveControl
{
    /**
     * Toggles the solenoid valve.
     *
     * @param bool $State
     * false    = close valve
     * true     = open valve
     *
     * @return bool
     * false    = an error occurred
     * true     = ok
     */
    public function ToggleSolenoidValve(bool $State): bool
    {
        // Close
        if (!$State) {
            $toggle = $this->CloseSolenoidValve();
        }
        // Open
        else {
            $toggle = $this->OpenSolenoidValve();
        }
        return $toggle;
    }

    /**
     * Set the cycle time.
     * @param float $Time
     */
    public function SetCycleTime(float $Time): void
    {
        $this->SetValue('CycleTime', $Time);
    }

    /**
     * Opens the solenoid valve.
     *
     * @return bool
     * false    = an error occurred
     * true     = ok
     */
    public function OpenSolenoidValve(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Das Magnetventil wird geöffnet.', 0);
        // Set timer
        $duration = $this->GetValue('CycleTime');
        $this->SetTimerInterval('CloseSolenoidValve', $duration * 1000);
        $timestamp = time() + (int) (round($duration, 0));
        $this->SetValue('TimerInfo', date('d.m.Y, H:i:s', ($timestamp)));
        // Open valve
        if (!$this->CheckSolenoidValve()) {
            return false;
        }
        $id = $this->ReadPropertyInteger('SolenoidValve');
        $open = @RequestAction($id, true);
        // 2nd try
        if (!$open) {
            $open = @RequestAction($id, true);
        }
        // Log & Debug
        if (!$open) {
            $text = 'Das Magnetventil konnte nicht geöffnet werden. (ID ' . $id . ')';
            $this->LogMessage(__FUNCTION__ . ', ' . $text, KL_ERROR);
        } else {
            $text = 'Das Magnetventil wurde geöffnet. (ID ' . $id . ')';
            $this->SetValue('SolenoidValve', true);
        }
        $this->SendDebug(__FUNCTION__, $text, 0);
        return $open;
    }

    /**
     * Closes the solenoid valve.
     *
     * @return bool
     * false    = an error occurred
     * true     = ok
     */
    public function CloseSolenoidValve(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Das Magnetventil wird geschlossen.', 0);
        // Disable timer
        $this->DisableTimers();
        // Close valve
        if (!$this->CheckSolenoidValve()) {
            return false;
        }
        $id = $this->ReadPropertyInteger('SolenoidValve');
        $close = @RequestAction($id, false);
        // 2nd try
        if (!$close) {
            $close = @RequestAction($id, false);
        }
        // Log & Debug
        if (!$close) {
            $text = 'Das Magnetventil konnte nicht geschlossen werden. (ID ' . $id . ')';
            $this->LogMessage(__FUNCTION__ . ', ' . $text, KL_ERROR);
        } else {
            $text = 'Das Magnetventil wurde geschlossen. (ID ' . $id . ')';
            $this->SetValue('SolenoidValve', false);
        }
        $this->SendDebug(__FUNCTION__, $text, 0);
        return $close;
    }

    //#################### Private

    /**
     * Checks for an existing solenoid valve.
     *
     * @return bool
     * false    = no solenoid valve exists
     * true     = solenoid valve exists
     */
    private function CheckSolenoidValve(): bool
    {
        $exists = true;
        $id = $this->ReadPropertyInteger('SolenoidValve');
        if ($id == 0 || @!IPS_ObjectExists($id)) {
            $text = 'Abbruch, Es ist kein Magnetventil vorhanden!';
            $this->LogMessage(__FUNCTION__ . ', ' . $text, KL_ERROR);
            $this->SendDebug(__FUNCTION__, $text, 0);
            $exists = false;
        }
        return $exists;
    }

    /**
     * Checks the solenoid valve state
     */
    private function CheckSolenoidValveState(): void
    {
        if (!$this->CheckSolenoidValve()) {
            return;
        }
        $id = $this->ReadPropertyInteger('SolenoidValve');
        $state = GetValue($id);
        $this->SetValue('SolenoidValve', $state);
        $this->SetValue('ValveState', (int) $state);
    }
}