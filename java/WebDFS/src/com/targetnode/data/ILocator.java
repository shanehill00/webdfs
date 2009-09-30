/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */

package com.targetnode.data;

import com.targetnode.data.locator.LocatorException;
import java.util.ArrayList;
import java.util.HashMap;

/**
 *
 * @author shane
 */
public interface ILocator {
    public ArrayList<HashMap> findNodes( String objKey ) throws LocatorException;
}
