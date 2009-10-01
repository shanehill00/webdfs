/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */

package com.targetnode.rushdfs;

import com.targetnode.data.ILocator;
import java.io.InputStream;
import java.util.HashMap;

/**
 *
 * @author shane
 */
public class Request {

    protected HashMap<String,Object> params = null;
    protected ILocator locator = null;
    protected String finalDir = null;
    protected String finalPath = null;
    protected String tmpPath = null;
    protected boolean sync = false;
    protected boolean canSelfHeal = false;
    protected byte[] readBuffer = null;
    protected HashMap<String, Object> dataConfig = null;
    protected InputStream inputStream = null;

    public InputStream getInputStream() {
        return inputStream;
    }

    public void setInputStream(InputStream inputStream) {
        this.inputStream = inputStream;
    }

    public HashMap<String, Object> getDataConfig() {
        return dataConfig;
    }

    public void setDataConfig(HashMap<String, Object> dataConfig) {
        this.dataConfig = dataConfig;
    }

    public boolean isSync() {
        return sync;
    }

    public void setSync(boolean sync) {
        this.sync = sync;
    }

    public String getProxyUrl(){
        return (String) dataConfig.get("thisProxyUrl");
    }

    public byte[] getReadBuffer() {
        return readBuffer;
    }

    public void setReadBuffer(byte[] readBuffer) {
        this.readBuffer = readBuffer;
    }

    public boolean canSelfHeal() {
        return canSelfHeal;
    }

    public void setCanSelfHeal(boolean canSelfHeal) {
        this.canSelfHeal = canSelfHeal;
    }

    public String getFinalDir() {
        return finalDir;
    }

    public void setFinalDir(String finalDir) {
        this.finalDir = finalDir;
    }

    public String getFinalPath() {
        return finalPath;
    }

    public void setFinalPath(String finalPath) {
        this.finalPath = finalPath;
    }

    public boolean shouldSync() {
        return sync;
    }

    public void setFsync(boolean fsync) {
        this.sync = fsync;
    }

    public ILocator getLocator() {
        return locator;
    }

    public void setLocator(ILocator locator) {
        this.locator = locator;
    }

    public HashMap<String, Object> getParams() {
        return params;
    }

    public void setParams(HashMap<String, Object> params) {
        this.params = params;
    }

    public String getTmpPath() {
        return tmpPath;
    }

    public void setTmpPath(String tmpPath) {
        this.tmpPath = tmpPath;
    }

}
